<?php

/**
 * @file plugins/generic/crossrefReferenceLinking/CrossrefReferenceLinkingInfoSender.php
 *
 * Copyright (c) 2013-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CrossrefReferenceLinkingInfoSender
 * @brief Scheduled task to check for found Crossref references DOIs.
 */

namespace APP\plugins\generic\crossrefReferenceLinking;

use APP\core\Application;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTask;

class CrossrefReferenceLinkingInfoSender extends ScheduledTask
{

	protected CrossrefReferenceLinkingPlugin $plugin;

	/**
	 * Constructor.
	 * @param $args array task arguments
	 */
	public function __construct($args)
	{
		$this->plugin = PluginRegistry::getPlugin('generic', 'crossrefreferencelinkingplugin');
		$this->plugin->addLocaleData();
		parent::__construct($args);
	}

	/**
	 * @copydoc ScheduledTask::getName()
	 */
	public function getName()
	{
		return __('plugins.generic.crossrefReferenceLinking.senderTask.name');
	}

	/**
	 * @copydoc ScheduledTask::executeActions()
	 */
	public function executeActions()
	{
		if (!$this->plugin) return false;
		foreach ($this->getJournals() as $journal) {
			// Call the plugin register function, in order to be able to save the new article and citation settings in the DB
			$this->plugin->register('generic', $this->plugin->getPluginPath(), $journal->getId());
			// Get published articles to check
			$submissionsToCheck = $this->plugin->getSubmissionsToCheck($journal);
			foreach ($submissionsToCheck as $submissionToCheck) { /** @var Article $submissionToCheck */
				$this->plugin->getCrossrefReferencesDOIs($submissionToCheck->getCurrentPublication());
			}
		}
		return true;
	}

	/**
	 * Get all journals that meet the requirements to have
	 * their articles or issues DOIs sent to Crossref.
	 */
	protected function getJournals(): array
	{
		$contextDao = Application::getContextDAO(); /** @var JournalDAO $contextDao */
		$contextFactory = $contextDao->getAll(true); /** @var DAOResultFactory $contextFactory */
		$journals = [];
		foreach ($contextFactory->toIterator() as $journal) { /** @var Journal $journal */
			if ($this->plugin->citationsEnabled($journal->getId()) &&
				$this->plugin->crossrefCredentials($journal->getId())) {
					$journals[] = $journal;
			}
		}
		return $journals;
	}
}
