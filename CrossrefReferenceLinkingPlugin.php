<?php

/**
 * @file CrossrefReferenceLinkingPlugin.inc.php
 *
 * Copyright (c) 2013-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CrossrefReferenceLinkingPlugin
 * @brief Reference Linking plugin class
 */

namespace APP\plugins\generic\crossrefReferenceLinking;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use APP\notification\Notification;
use APP\publication\Publication;
use APP\submission\Submission;
use Citation;
use Doi;
use DOMDocument;
use PKP\citation\CitationDAO;
use PKP\context\Context;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;

class CrossrefReferenceLinkingPlugin extends GenericPlugin implements HasTaskScheduler
{
    public const CROSSREF_API_REFS_URL = 'https://doi.crossref.org/getResolvedRefs';

    public const CROSSREF_API_REFS_URL_DEV = 'https://test.crossref.org/getResolvedRefs';

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $registered = parent::register($category, $path, $mainContextId);
        if (!$registered) {
            return false;
        }

        if (Application::isUnderMaintenance()) {
            return true;
        }

        // Additional fields added
        Hook::add('Schema::get::submission', [$this, 'addSubmissionSchema']);
        Hook::add('citationdao::getAdditionalFieldNames', [$this, 'getAdditionalCitationFieldNames']);

        if (!$this->getEnabled($mainContextId)) {
            return true;
        }

        if (!isset($mainContextId)) {
            $mainContextId = $this->getCurrentContextId();
        }
        if (!$this->hasCrossrefCredentials($mainContextId) || !$this->citationsEnabled($mainContextId)) {
            return true;
        }

        // Crossref export plugin hooks
        Hook::add('articlecrossrefxmlfilter::execute', [$this, 'addCrossrefCitationsElements']);
        Hook::add('crossrefexportplugin::deposited', [$this, 'getCitationsDiagnosticId']);

        // Citation changed hook
        Hook::add('Citation::importCitations::after', [$this, 'citationsChanged']);

        // Article page hooks
        Hook::add('Templates::Article::Details::Reference', [$this, 'displayReferenceDOI']);

        return true;
    }

    /**
     * Are Crossref username and password set in Crossref plugin
     */
    public function hasCrossrefCredentials(int $contextId): bool
    {
        // If crossref plugin is set i.e. the crossref credentials exist we can assume that DOI plugin is set correctly
        $crossrefPlugin = PluginRegistry::getPlugin('generic', 'crossrefplugin');
        return $crossrefPlugin && strlen((string) $crossrefPlugin->getSetting($contextId, 'username')) > 0 && strlen((string) $crossrefPlugin->getSetting($contextId, 'password')) > 0;
    }

    /**
     * Are citations submission metadata enabled in this journal
     */
    public function citationsEnabled(int $contextId): bool
    {
        $contextDao = Application::getContextDAO();
        $context = $contextDao->getById($contextId);
        return !empty($context->getSetting('citations'));
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.crossrefReferenceLinking.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.crossrefReferenceLinking.description');
    }

    /**
     * @see Plugin::getActions()
     */
    public function getActions($request, $actionArgs): array
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }
        $router = $request->getRouter();
        $linkAction = new LinkAction(
            id: 'settings',
            actionRequest: new AjaxModal(
                url: $router->url(
                    request: $request,
                    op: 'manage',
                    params: [
                        'verb' => 'settings',
                        'plugin' => $this->getName(),
                        'category' => 'generic'
                    ]
                ),
                title: $this->getDisplayName()
            ),
            title: __('manager.plugins.settings')
        );
        array_unshift($actions, $linkAction);
        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request): JSONMessage
    {
        $context = $request->getContext();
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
                        Notification::NOTIFICATION_TYPE_SUCCESS,
                        ['contents' => __('plugins.generic.crossrefReferenceLinking.settings.form.saved')]
                    );
                    return new JSONMessage(true);
                }
                return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }

    /**
     * @copydoc \PKP\plugins\interfaces\HasTaskScheduler::registerSchedules()
     */
    public function registerSchedules(PKPScheduler $scheduler): void
    {
        $scheduler
            ->addSchedule(new CrossrefReferenceLinkingInfoSender([]))
            ->hourly()
            ->name(CrossrefReferenceLinkingInfoSender::class)
            ->withoutOverlapping();
    }

    /**
     * Add references data to the Crossref XML export
     *
     * @param $hookName string 'articlecrossrefxmlfilter::execute'
     * @param $params array [
     *  @option DOMDocument Crossref filter output
     * ]
     */
    public function addCrossrefCitationsElements(string $hookName, array $params): bool
    {
        /** @var DOMDocument $preliminaryOutput */
        $preliminaryOutput =& $params[0];
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        // Crossref export cannot be executed via CLI any more, thus there will always be a context
        $contextId = $context->getId();

        $rfNamespace = 'http://www.crossref.org/schema/5.4.0';
        $articleNodes = $preliminaryOutput->getElementsByTagName('journal_article');
        foreach ($articleNodes as $articleNode) {
            $doiDataNode = $articleNode->getElementsByTagName('doi_data')->item(0);
            $doiNode = $doiDataNode->getElementsByTagName('doi')->item(0);
            $doiValue = $doiNode->nodeValue;
            // There should be only one DOI
            /** @var Doi $doi */
            $doi = Repo::doi()->getCollector()->filterByContextIds([$contextId])->filterByIdentifier($doiValue)->getMany()->first();
            if (!$doi) {
                return false;
            }
            $publications = Repo::publication()->getCollector()->filterByDoiIds([$doi->getId()])->getMany();
            if ($publications->count() < 1) {
                return false;
            }
            $submission = Repo::submission()->get($publications->first()->getData('submissionId'));
            $articleCitations = $submission->getCurrentPublication()->getData('citations');
            if (!empty($articleCitations)) {
                $citationListNode = $preliminaryOutput->createElementNS($rfNamespace, 'citation_list');
                foreach ($articleCitations as $citation) {
                    $rawCitation = $citation->getRawCitation();
                    if (!empty($rawCitation)) {
                        $citationNode = $preliminaryOutput->createElementNS($rfNamespace, 'citation');
                        $citationNode->setAttribute('key', $citation->getId());
                        // if Crossref DOI already exists for this citation, include it
                        // else include unstructured raw citation
                        if ($citation->getData($this->getCitationDoiSettingName())) {
                            $node = $preliminaryOutput->createElementNS($rfNamespace, 'doi');
                            $node->appendChild($preliminaryOutput->createTextNode($citation->getData($this->getCitationDoiSettingName())));
                        } else {
                            $node = $preliminaryOutput->createElementNS($rfNamespace, 'unstructured_citation');
                            $node->appendChild($preliminaryOutput->createTextNode($rawCitation));
                        }
                        $citationNode->appendChild($node);
                        $citationListNode->appendChild($citationNode);
                    }
                }
                $doiDataNode->parentNode->insertBefore($citationListNode, $doiDataNode->nextSibling);
            }
        }
        return Hook::CONTINUE;
    }

    /**
     * During the article DOI registration with Crossref, get the citations diagnostic ID from the Crossref response.
     *
     * @param $hookName string Hook name 'crossrefexportplugin::deposited'
     * @param $params array [
     *  @option CrossrefExportPlugin
     *  @option string XML response from Crossref deposit
     *  @option Submission
     * ]
     */
    public function getCitationsDiagnosticId(string $hookName, array $params): bool
    {
        /** @var string $response */
        $response = & $params[1];
        /** @var Submission $submission */
        $submission = & $params[2];
        // Get DOMDocument from the response XML string
        $xmlDoc = new DOMDocument();
        $xmlDoc->loadXML($response);
        if ($xmlDoc->getElementsByTagName('citations_diagnostic')->length > 0) {
            $citationsDiagnosticNode = $xmlDoc->getElementsByTagName('citations_diagnostic')->item(0); /** @var DOMNodeList $citationsDiagnosticNode */
            $citationsDiagnosticCode = $citationsDiagnosticNode->getAttribute('deferred') ;
            //set the citations diagnostic code and the setting for the automatic check
            $submission->setData($this->getCitationsDiagnosticIdSettingName(), $citationsDiagnosticCode);
            $submission->setData($this->getAutoCheckSettingName(), true);
            Repo::submission()->edit($submission, []);
            $submission = Repo::submission()->get($submission->getId());
        }
        return Hook::CONTINUE;
    }

    /**
     * Add properties to the submission entity (SchemaDAO-based)
     *
     * @param $hookName string `Schema::get::submission`
     * @param array $args [
     *      @option stdClass $schema
     * ]
     */
    public function addSubmissionSchema(string $hookName, array $args): bool
    {
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
        return Hook::CONTINUE;
    }

    /**
     * Consider the additional citation setting name 'crossref::doi'.
     */
    public function getAdditionalCitationFieldNames(string $hookName, CitationDAO $citationDao, array &$additionalFields): bool
    {
        $additionalFields[] = $this->getCitationDoiSettingName();
        return Hook::CONTINUE;
    }

    /**
     * Resets the submission data related to Reference Linking Plugin.
     * Used every time the citations for a certain publication are imported.
     *
     * @param $hookName string 'Citation::importCitations::after'
     */
    public function citationsChanged(string $hookName, int $publicationId, array $existingCitations, array $importedCitations): bool
    {
        $publication = Repo::publication()->get($publicationId);
        $submission = Repo::submission()->get($publication->getData('submissionId'));

        if ($submission->getData($this->getCitationsDiagnosticIdSettingName())) {
            $submission->setData($this->getCitationsDiagnosticIdSettingName(), null);
            $submission->setData($this->getAutoCheckSettingName(), null);
            Repo::submission()->edit($submission, []);
        }
        return Hook::CONTINUE;
    }

    /**
     * Get found Crossref references DOIs for the given publication DOI.
     */
    public function considerFoundCrossrefReferencesDOIs(Publication $publication): void
    {
        $doi = urlencode($publication->getDoi());
        if (empty($doi)) {
            return;
        }

        $citationDao = DAORegistry::getDAO('CitationDAO'); /** @var CitationDAO $citationDao */
        $citations = $publication->getData('citations') ?? [];

        $citationsToCheck = [];
        foreach ($citations as $citation) { /** @var Citation $citation */
            if (!$citation->getData($this->getCitationDoiSettingName())) {
                $citationsToCheck[$citation->getId()] = $citation;
            }
        }
        if (empty($citationsToCheck)) {
            return;
        }

        $citationsToCheckKeys = array_keys($citationsToCheck);

        $submission = Repo::submission()->get($publication->getData('submissionId'));

        $matchedReferences = $this->getResolvedRefs($doi, $submission->getData('contextId'));
        if ($matchedReferences) {
            $filteredMatchedReferences = array_filter(
                $matchedReferences,
                fn ($value) => in_array($value['key'], $citationsToCheckKeys)
            );

            foreach ($filteredMatchedReferences as $matchedReference) {
                $citation = $citationsToCheck[$matchedReference['key']];
                $citation->setData($this->getCitationDoiSettingName(), $matchedReference['doi']);
                $citationDao->updateObject($citation);
            }

            // remove auto check setting
            $submission->setData($this->getAutoCheckSettingName(), null);
            Repo::submission()->edit($submission, []);
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
     */
    public function displayReferenceDOI(string $hookName, array $params): bool
    {
        /** @var Citation $citation */
        $citation = $params[0]['citation'];
        /** @var \Smarty $smarty */
        $smarty = &$params[1];
        /** @var string $output */
        $output = &$params[2];

        if ($citation->getData($this->getCitationDoiSettingName())) {
            $crossrefFullUrl = 'https://doi.org/' . $citation->getData($this->getCitationDoiSettingName());
            $smarty->assign('crossrefFullUrl', $crossrefFullUrl);
            $output .= $smarty->fetch($this->getTemplateResource('displayDOI.tpl'));
        }
        return Hook::CONTINUE;
    }

    /**
     * Get citations diagnostic ID setting name.
     */
    public function getCitationsDiagnosticIdSettingName(): string
    {
        return 'crossref::citationsDiagnosticId';
    }

    /**
     * Get citation crossref DOI setting name.
     */
    public function getCitationDoiSettingName(): string
    {
        return 'crossref::doi';
    }

    /**
     * Get setting name, that defines if the scheduled task for the automatic check
     * of the found Crossref citations DOIs should be run, if set up so in the plugin settings.
     */
    public function getAutoCheckSettingName(): string
    {
        return 'crossref::checkCitationsDOIs';
    }

    /**
     * Retrieve all submissions that should be automatically checked for the found Crossref citations DOIs.
     *
     * @return Submission[]
     */
    public function getSubmissionsToCheck(Context $context): array
    {
        // Retrieve all published articles with their DOIs deposited together with the references.
        // i.e. with the citations diagnostic ID setting
        $submissionIds = Repo::submission()->getIdsBySetting($this->getAutoCheckSettingName(), true, $context->getId())->toArray();
        $submissions = array_map(function ($submissionId) {
            return Repo::submission()->get($submissionId);
        }, $submissionIds);

        return array_filter($submissions, function ($submission) {
            return $submission->getData('status') === Submission::STATUS_PUBLISHED;
        });
    }

    /**
     * Use Crossref API to get the references DOIs for the given article DOI.
     */
    protected function getResolvedRefs(string $doi, int $contextId): ?array
    {
        $matchedReferences = null;

        PluginRegistry::loadCategory('generic');
        $crossrefPlugin = PluginRegistry::getPlugin('generic', 'crossrefplugin');
        $username = $crossrefPlugin->getSetting($contextId, 'username');
        $password = $crossrefPlugin->getSetting($contextId, 'password');

        // Use a different endpoint for testing and production.
        $isTestMode = $crossrefPlugin->getSetting($contextId, 'testMode') == 1;
        $endpoint = ($isTestMode ? self::CROSSREF_API_REFS_URL_DEV : self::CROSSREF_API_REFS_URL);

        $url = $endpoint . '?doi=' . $doi . '&usr=' . $username . '&pwd=' . $password;

        $httpClient = Application::get()->getHttpClient();
        try {
            $response = $httpClient->request('POST', $url);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            return null;
        }

        if ($response?->getStatusCode() == 200) {
            $response = json_decode($response->getBody(), true);
            $matchedReferences = $response['matched-references'];
        }

        return $matchedReferences;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\crossrefReferenceLinking\CrossrefReferenceLinkingPlugin', '\CrossrefReferenceLinkingPlugin');
}
