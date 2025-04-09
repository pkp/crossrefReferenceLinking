<?php

/**
 * @file CrossrefReferenceLinkingSettingsForm.inc.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CrossrefReferenceLinkingSettingsForm
 * @ingroup plugins_generic_crossrefReferenceLinking
 *
 * @brief Form for journal managers to setup the reference linking plugin
 */

namespace APP\plugins\generic\crossrefReferenceLinking;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;

class CrossrefReferenceLinkingSettingsForm extends Form
{
    public function __construct(public CrossrefReferenceLinkingPlugin $plugin, public int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * @copydoc Form::fetch()
     */
    public function fetch($request, $template = null, $display = false): string
    {
        $dispatcher = $request->getDispatcher();
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('pluginName', $this->plugin->getName());
        if (!$this->plugin->hasCrossrefCredentials($this->contextId)) {
            // Settings > Distribution > DOIs > Registration
            $crossrefSettingsLinkAction = new LinkAction(
                'settings',
                new RedirectAction($dispatcher->url(
                    $request,
                    Application::ROUTE_PAGE,
                    null,
                    'management',
                    'settings',
                    ['distribution'],
                    [],
                    'dois/doisRegistration' // Anchor for tab
                )),
                __('plugins.generic.crossrefReferenceLinking.settings.form.crossrefSettings'),
                null
            );
            $templateMgr->assign('crossrefSettingsLinkAction', $crossrefSettingsLinkAction);
        }
        if (!$this->plugin->citationsEnabled($this->contextId)) {
            // Settings > Workflow > Submission > Metadata
            $submissionSettingsLinkAction = new LinkAction(
                'settings',
                new RedirectAction($dispatcher->url(
                    $request,
                    Application::ROUTE_PAGE,
                    null,
                    'management',
                    'settings',
                    ['workflow'],
                    [],
                    'submission/metadata' // Anchor for tab
                )),
                __('plugins.generic.crossrefReferenceLinking.settings.form.submissionSettings'),
                null
            );
            $templateMgr->assign('submissionSettingsLinkAction', $submissionSettingsLinkAction);
        }
        return parent::fetch($request);
    }
}
