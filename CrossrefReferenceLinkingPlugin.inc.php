<?php

/**
 * @file CrossrefReferenceLinkingPlugin.inc.php
 *
 * Copyright (c) 2013-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CrossrefReferenceLinkingPlugin
 * @ingroup plugins_generic_crossrefReferenceLinking
 *
 * @brief Reference Linking plugin class
 */

import('lib.pkp.classes.plugins.GenericPlugin');

define('CROSSREF_API_REFS_URL', 'https://doi.crossref.org/getResolvedRefs?doi=');
// TESTING
define('CROSSREF_API_REFS_URL_DEV', 'https://test.crossref.org/getResolvedRefs?doi=');


class CrossrefReferenceLinkingPlugin extends GenericPlugin {
	/**
	 * @copydoc Plugin::register()
	 */
	function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if ($success && $this->getEnabled($mainContextId)) {
			if (!isset($mainContextId)) $mainContextId = $this->getCurrentContextId();
			if ($this->crossrefCredentials($mainContextId) && $this->citationsEnabled($mainContextId)) {
				// register scheduled task
				HookRegistry::register('AcronPlugin::parseCronTab', array($this, 'callbackParseCronTab'));

				// Additional fields added
				HookRegistry::register('Schema::get::submission', array($this, 'addSubmissionSchema'));
				HookRegistry::register('citationdao::getAdditionalFieldNames', array($this, 'getAdditionalCitationFieldNames'));

				// crossref export plugin hooks
				HookRegistry::register('articlecrossrefxmlfilter::execute', array($this, 'addCrossrefCitationsElements'));
				HookRegistry::register('crossrefexportplugin::deposited', array($this, 'getCitationsDiagnosticId'));

				// Citation Changed hook
				HookRegistry::register('CitationDAO::afterImportCitations', array($this, 'citationsChanged'));

				// Publication Published hook
				HookRegistry::register('Publication::publish', array($this, 'checkPublicationsCitations'));

				// article page hooks
				HookRegistry::register('Templates::Article::Details::Reference', array($this, 'displayReferenceDOI'));
				HookRegistry::register('Templates::Controllers::Tab::PublicationEntry::Form::CitationsForm::Citation', array($this, 'displayReferenceDOI'));

			}
		}
		return $success;
	}

	/**
	 * Are Crossref username and password set in Crossref Export Plugin
	 * @param $contextId integer
	 * @return boolean
	 */
	function crossrefCredentials($contextId) {
		// If crossref export plugin is set i.e. the crossref credentials exist we can assume that DOI plugin is set correctly
		PluginRegistry::loadCategory('importexport');
		$crossrefExportPlugin = PluginRegistry::getPlugin('importexport', 'CrossRefExportPlugin');
		return $crossrefExportPlugin && $crossrefExportPlugin->getSetting($contextId, 'username') && $crossrefExportPlugin->getSetting($contextId, 'password');
	}

	/**
	 * Are citations submission metadata enabled in this journal
	 * @param $contextId integer
	 * @return boolean
	 */
	function citationsEnabled($contextId) {
		$contextDao = Application::getContextDAO();
		$context = $contextDao->getById($contextId);
		return !empty($context->getSetting('citations'));
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.generic.crossrefReferenceLinking.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	function getDescription() {
		return __('plugins.generic.crossrefReferenceLinking.description');
	}

	/**
	 * Get the handler path for this plugin.
	 * @return string
	 */
	function getHandlerPath() {
		return $this->getPluginPath() . '/pages/';
	}

	/**
	 * @see Plugin::getActions()
	 */
	public function getActions($request, $actionArgs) {
		$actions = parent::getActions($request, $actionArgs);
		if (!$this->getEnabled()) {
			return $actions;
		}
		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		$linkAction = new LinkAction(
			'settings',
			new AjaxModal(
				$router->url(
					$request,
					null,
					null,
					'manage',
					null,
					array(
						'verb' => 'settings',
						'plugin' => $this->getName(),
						'category' => 'generic'
					)
				),
				$this->getDisplayName()
			),
			__('manager.plugins.settings'),
			null
		);
		array_unshift($actions, $linkAction);
		return $actions;
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	function manage($args, $request) {
		$context = $request->getContext();
		$this->import('CrossrefReferenceLinkingSettingsForm');
		switch ($request->getUserVar('verb')) {
			case 'settings':
				$form = new CrossrefReferenceLinkingSettingsForm($this, $context->getId());
				$form->initData();
				return new JSONMessage(true, $form->fetch($request));
			case 'save':
				$form = new CrossrefReferenceLinkingSettingsForm($this, $context->getId());
				$form->readInputData();
				if ($form->validate()) {
					$form->execute($request);
					$notificationManager = new NotificationManager();
					$notificationManager->createTrivialNotification(
						$request->getUser()->getId(),
						NOTIFICATION_TYPE_SUCCESS,
						array('contents' => __('plugins.generic.crossrefReferenceLinking.settings.form.saved'))
					);
					return new JSONMessage(true);
				}
				return new JSONMessage(true, $form->fetch($request));
		}
		return parent::manage($args, $request);
	}

	/**
	 * @see AcronPlugin::parseCronTab()
	 * @param $hookName string
	 * @param $args array [
	 *  @option array Task files paths
	 * ]
	 * @return boolean
	 */
	function callbackParseCronTab($hookName, $args) {
		if ($this->getEnabled() || !Config::getVar('general', 'installed')) {
			$taskFilesPath =& $args[0]; // Reference needed.
			$taskFilesPath[] = $this->getPluginPath() . DIRECTORY_SEPARATOR . 'scheduledTasks.xml';
		}
		return false;
	}

	/**
	 * Hook to articlecrossrefxmlfilter::execute and add references data to the Crossref XML export
	 * @param $hookName string
	 * @param $params array [
	 *  @option DOMDocument Crossref filter output
	 * ]
	 * @return boolean
	 */
	function addCrossrefCitationsElements($hookName, $params) {
		$preliminaryOutput =& $params[0];
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		// if Crossref export is executed via CLI, there will be no context
		$contextId = isset($context) ? $context->getId() : null;
		$publicationDAO = DAORegistry::getDAO('PublicationDAO'); /** @var $publicationDAO PublicationDAO */
		$citationDao = DAORegistry::getDAO('CitationDAO'); /** @var $citationDao CitationDAO */

		$rfNamespace = 'http://www.crossref.org/schema/4.3.6';
		$articleNodes = $preliminaryOutput->getElementsByTagName('journal_article');
		foreach ($articleNodes as $articleNode) {
			$doiDataNode = $articleNode->getElementsByTagName('doi_data')->item(0);
			$doiNode = $doiDataNode->getElementsByTagName('doi')->item(0);
			$doi = $doiNode->nodeValue;

			$publicationIds = $publicationDAO->getIdsBySetting('pub-id::doi', $doi, $contextId);

			assert(count($publicationIds) >= 1);
			if (count($publicationIds) >= 1) {
				$submissionDao = DAORegistry::getDAO('SubmissionDAO');
				$publicationService = Services::get('publication');
				$submissionService = Services::get('submission');

				$publication = $publicationService->get($publicationIds[0]);
				$submission = $submissionService->get($publication->getData('submissionId')); /** @var $submission Submission*/

				$articleCitations = $citationDao->getByPublicationId($submission->getCurrentPublication()->getId());
				$articleCitationsArray = $articleCitations->toArray();
				if (!empty($articleCitationsArray)) {
					$citationListNode = $preliminaryOutput->createElementNS($rfNamespace, 'citation_list');
					foreach($articleCitationsArray as $citation) {
						$rawCitation = $citation->getRawCitation();
						if (!empty($rawCitation)) {
							$citationNode = $preliminaryOutput->createElementNS($rfNamespace, 'citation');
							$citationNode->setAttribute('key', $citation->getId());
							// if Crossref DOI already exists for this citation, include it
							// else include unstructred raw citation
							if ($citation->getData($this->getCitationDoiSettingName())) {
								$citationNode->appendChild($node = $preliminaryOutput->createElementNS($rfNamespace, 'doi', htmlspecialchars($citation->getData($this->getCitationDoiSettingName()), ENT_COMPAT, 'UTF-8')));
							} else {
								$citationNode->appendChild($node = $preliminaryOutput->createElementNS($rfNamespace, 'unstructured_citation', htmlspecialchars($rawCitation, ENT_COMPAT, 'UTF-8')));
							}
							$citationListNode->appendChild($citationNode);
						}
					}
					$doiDataNode->parentNode->insertBefore($citationListNode, $doiDataNode->nextSibling);
				}
			}
		}
		return false;
	}

	/**
	 * During the article DOI registration with Crossref, get the citations diagnostic ID from the Crossref response.
	 *
	 * @param $hookName string Hook name
	 * @param $params array [
	 *  @option CrossrefExportPlugin
	 *  @option string XML reposonse from Crossref deposit
	 *  @option Submission
	 * ]
	 * @return boolean
	 */
	function getCitationsDiagnosticId($hookName, $params) {
		$response = & $params[1];
		$submission = & $params[2];
		// Get DOMDocument from the response XML string
		$xmlDoc = new DOMDocument();
		$xmlDoc->loadXML($response);
		if ($xmlDoc->getElementsByTagName('citations_diagnostic')->length > 0) {
			$citationsDiagnosticNode = $xmlDoc->getElementsByTagName('citations_diagnostic')->item(0);
			$citationsDiagnosticCode = $citationsDiagnosticNode->getAttribute('deferred') ;
			//set the citations diagnostic code and the setting fot the automatic check
			$submission->setData($this->getCitationsDiagnosticIdSettingName(), $citationsDiagnosticCode);
			$submission->setData($this->getAutoCheckSettingName(), true);

			$submission = Services::get('submission')->edit($submission, array(), Application::get()->getRequest());
		}

		return false;
	}

	/**
	 * Add properties to the submission entity (SchemaDAO-based)
	 * @param $hookName string `Schema::get::submission`
	 * @param $params array
	 */
	function addSubmissionSchema($hookName, $args) {
		$schema = $args[0];

		$schema->properties->{$this->getCitationsDiagnosticIdSettingName()} = (object) [
			'type' => 'string',
			'apiSummary' => true,
			'validation' => ['nullable']
		];

		$schema->properties->{$this->getAutoCheckSettingName()} = (object) [
			'type' => 'boolean',
			'apiSummary' => true,
			'validation' => ['nullable']
		];
	}

	/**
	 * Hook callback that returns the additional citation setting name
	 * "crossref::doi".
	 * @see DAO::getAdditionalFieldNames()
	 * @param $hookName string
	 * @param $params array [
	 *  @option CitationDAO
	 *  @option array List of strings representing field names
	 * ]
	 */
	function getAdditionalCitationFieldNames($hookName, $params) {
		$additionalFields =& $params[1];
		assert(is_array($additionalFields));
		$additionalFields[] = $this->getCitationDoiSettingName();
	}

	/**
	 * Resets the submission data related to Reference Linking Plugin.
	 * Used every time the citations for a certain publication are imported.
	 * @param $hookName string CitationDAO::afterImportCitations
	 * @param $params array [
	 *  @option integer publicationId The publicationId for which the citations are imported
	 * ]
	 */
	function citationsChanged($hookName, $params) {
		$publicationId = $params[0];

		$publication = Services::get('publication')->get($publicationId);
		$submission = Services::get('submission')->get($publication->getData('submissionId'));

		if ($submission->getData($this->getCitationsDiagnosticIdSettingName())) {
			$submission->setData($this->getCitationsDiagnosticIdSettingName(), null);
			$submission->setData($this->getAutoCheckSettingName(), null);

			$submission = Services::get('submission')->edit($submission, array(), Application::get()->getRequest());
		}

		return false;
	}

	/**
	 * A certain submission is being checked for reference linking
	 * Used every time the publication is being published.
	 * This only applies/makes sense when:
	 *  1) the publication is published
	 *  2) Crossref DOIs for the article deposited
	 *  3) the publication unpublished and then published again.
	 *
	 * @param $hookName string Publication::publish
	 * @param $params array [
	 *  @option Publication The newPublication after it's published
	 *  @option Publication The publication before it's published
	 *  @option Submission The submission related to the published publication
	 * ]
	 */
	function checkPublicationsCitations($hookName, $params) {
		$newPublication =& $params[0]; /** @var $newPublication Publication */
		$publication =& $params[1]; /** @var $publication Publication */
		$submission =& $params[2]; /** @var $submission Submission */

		$request = PKPApplication::get()->getRequest();

		if ($submission->getData($this->getCitationsDiagnosticIdSettingName())) {
			$this->getCrossrefReferencesDOIs($publication);
		}

		return false;
	}

	/**
	 * Get found Crossref references DOIs for the given publication DOI.
	 * @param $publication Publication
	 */
	function getCrossrefReferencesDOIs($publication) {
		$doi = urlencode($publication->getStoredPubId('doi'));

		if (!empty($doi)) {
			$citationDao = DAORegistry::getDAO('CitationDAO'); /** @var $citationDao CitationDAO */
			$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var $submissionDao SubmissionDAO */

			$citations = $citationDao->getByPublicationId($publication->getId())->toAssociativeArray();

			$citationsToCheck = array();
			foreach ($citations as $citation) { /** @var $citation Citation */
				if (!$citation->getData($this->getCitationDoiSettingName())) {
					$citationsToCheck[$citation->getId()] = $citation;
				}
			}
			if (!empty($citationsToCheck)) {
				$citationsToCheckKeys = array_keys($citationsToCheck);

				$submission = Services::get('submission')->get($publication->getData('submissionId'));

				$matchedReferences = $this->_getResolvedRefs($doi, $submission->getData('contextId'));
				if ($matchedReferences) {
					$filteredMatchedReferences = array_filter($matchedReferences, function ($value) use ($citationsToCheckKeys) {
						return in_array($value['key'], $citationsToCheckKeys);
					});

					foreach ($filteredMatchedReferences as $matchedReference) {
						$citation = $citationsToCheck[$matchedReference['key']];
						$citation->setData($this->getCitationDoiSettingName(), $matchedReference['doi']);
						$citationDao->updateObject($citation);
					}

					// remove auto check setting
					$submission->setData($this->getAutoCheckSettingName(), null);

					$submission = Services::get('submission')->edit($submission, array(), Application::get()->getRequest());
				}
			}
		}
	}

	/**
	 * Insert reference DOI on the citations and article view page.
	 *
	 * @param $hookName string Hook name
	 * @param $params array [
	 *  @option Citation
	 *  @option Smarty
	 *  @option string Rendered smarty template
	 * ]
	 * @return boolean
	 */
	function displayReferenceDOI($hookName, $params) {
		$citation =& $params[0]['citation'];
		$smarty =& $params[1];
		$output =& $params[2];

		if ($citation->getData($this->getCitationDoiSettingName())) {
			$crossrefFullUrl = 'https://doi.org/' . $citation->getData($this->getCitationDoiSettingName());
			$smarty->assign('crossrefFullUrl', $crossrefFullUrl);
			$output .= $smarty->fetch($this->getTemplateResource('displayDOI.tpl'));
		}
		return false;
	}

	/**
	 * Get citations diagnostic ID setting name.
	 * @return string
	 */
	function getCitationsDiagnosticIdSettingName() {
		return 'crossref::citationsDiagnosticId';
	}

	/**
	 * Get citation crossref DOI setting name.
	 * @return string
	 */
	function getCitationDoiSettingName() {
		return 'crossref::doi';
	}

	/**
	 * Get setting name, that defines if the scheduled task for the automatic check
	 * of the found Crossref citations DOIs should be run, if set up so in the plugin settings.
	 * @return string
	 */
	function getAutoCheckSettingName() {
		return 'crossref::checkCitationsDOIs';
	}

	/**
	 * Retrieve all submissions that should be automatically checked for the found Crossref citations DOIs.
	 * @param $context Context
	 * @return array Submission
	 */
	function getSubmissionsToCheck($context) {
		// Retrieve all published articles with their DOIs depositted together with the references.
		// i.e. with the citations diagnostic ID setting
		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var $submissionDao SubmissionDAO */
		$submissionIds = $submissionDao->getIdsBySetting($this->getAutoCheckSettingName(), true, $context->getId());

		$submissions = array_map(function($submissionId) {
			return Services::get('submission')->get($submissionId);
		}, $submissionIds);

		return array_filter($submissions, function($submission) {
			return $submission->getData('status') === STATUS_PUBLISHED;
		});
	}

	/**
	 * Use Crossref API to get the references DOIs for the the given article DOI.
	 * @param $doi string
	 * @param $contextId integer
	 * @return NULL|array
	 */
	function _getResolvedRefs($doi, $contextId) {
		$matchedReferences = null;

		PluginRegistry::loadCategory('importexport');
		$crossrefExportPlugin = PluginRegistry::getPlugin('importexport', 'CrossRefExportPlugin');
		$username = $crossrefExportPlugin->getSetting($contextId, 'username');
		$password = $crossrefExportPlugin->getSetting($contextId, 'password');

		// Use a different endpoint for testing and production.
		$isTestMode = $crossrefExportPlugin->getSetting($contextId, 'testMode') == 1;
		$endpoint = ($isTestMode ? CROSSREF_API_REFS_URL_DEV : CROSSREF_API_REFS_URL);

		$url = $endpoint.$doi.'&usr='.$username.'&pwd='.$password;

		$httpClient = Application::get()->getHttpClient();
		try {
			$response = $httpClient->request('POST', $url);
		} catch (GuzzleHttp\Exception\RequestException $e) {
			return null;
		}

		if ($response && $response->getStatusCode() == 200)  {
			$response = json_decode($response->getBody(), true);
			$matchedReferences = $response['matched-references'];
		}

		return $matchedReferences;
	}
}

