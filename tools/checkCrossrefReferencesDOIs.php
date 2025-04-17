<?php

/**
 * @file tools/checkCrossrefReferencesDOIs.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CrossrefReferencesDOIsTool
 * @brief CLI tool to check found Crossref citations DOIs
 */

require(dirname(__FILE__, 5) . '/tools/bootstrap.php');

use APP\core\Application;
use APP\facades\Repo;
use PKP\plugins\PluginRegistry;

class CrossrefReferencesDOIsTool extends \PKP\cliTool\CommandLineTool
{
    public array $parameters;

    /**
     * Constructor.
     * @param $argv array command-line arguments
     */
    public function __construct($argv = [])
    {
        parent::__construct($argv);
        if (!sizeof($this->argv)) {
            $this->usage();
            exit(1);
        }
        $this->parameters = $this->argv;
    }

    /**
     * Print command usage information.
     */
    public function usage(): void
    {
        echo _('plugins.generic.crossrefReferenceLinking.citationsFormActionName') . "\n"
            . "Usage:\n"
            . "{$this->scriptName} all\n"
            . "{$this->scriptName} context context_id [...]\n"
            . "{$this->scriptName} submission submission_id [...]\n";
    }

    /**
     * Check citations DOIs
     */
    public function execute(): void
    {
        $contextDao = Application::getContextDAO();

        switch(array_shift($this->parameters)) {
            case 'all':
                $contexts = $contextDao->getAll();
                while ($context = $contexts->next()) {
                    $plugin = PluginRegistry::loadPlugin('generic', 'crossrefReferenceLinking', $context->getId()); /** @var CrossrefReferenceLinkingPlugin $plugin */
                    // Get published articles to check
                    $submissionsToCheck = $plugin->getSubmissionsToCheck($context);
                    foreach ($submissionsToCheck as $submissionToCheck) { /** @var Submission $submissionToCheck */
                        $plugin->considerFoundCrossrefReferencesDOIs($submissionToCheck->getCurrentPublication());
                    }
                }
                break;
            case 'context':
                foreach ($this->parameters as $contextId) {
                    $context = $contextDao->getById($contextId);
                    if (!isset($context)) {
                        printf("Error: Skipping $contextId. Unknown context.\n");
                        continue;
                    }
                    $plugin = PluginRegistry::loadPlugin('generic', 'crossrefReferenceLinking', $context->getId()); /** @var CrossrefReferenceLinkingPlugin $plugin */
                    // Get published articles to check
                    $submissionsToCheck = $plugin->getSubmissionsToCheck($context);
                    foreach ($submissionsToCheck as $submissionToCheck) { /** @var Submission $submissionToCheck */
                        $plugin->considerFoundCrossrefReferencesDOIs($submissionToCheck->getCurrentPublication());
                    }
                }
                break;
            case 'submission':
                foreach ($this->parameters as $submissionId) {
                    $submission = Repo::submission()->get($submissionId);
                    if (!isset($submission)) {
                        printf("Error: Skipping $submissionId. Unknown submission.\n");
                        continue;
                    }
                    $plugin = PluginRegistry::loadPlugin('generic', 'crossrefReferenceLinking', $submission->getData('contextId')); /** @var CrossrefReferenceLinkingPlugin $plugin */
                    $plugin->considerFoundCrossrefReferencesDOIs($submission->getCurrentPublication());
                }
                break;
            default:
                $this->usage();
                break;
        }
    }
}

$tool = new CrossrefReferencesDOIsTool($argv ?? []);
$tool->execute();
