<?php

/**
 * @file plugins/generic/downloadReviews/DownloadReviewsPlugin.inc.php
 *
 * @class DownloadReviewsPlugin
 * @ingroup plugins_generic_DownloadReviewsPlugin
 *
 * @brief DownloadReviews plugin class
 */

use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\core\JSONMessage;

class DownloadReviewsPlugin extends GenericPlugin {
    /**
     * @copydoc Plugin::register()
     */
    function register($category, $path, $mainContextId = null): bool
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }

        // Override OJS templates
        Hook::add('TemplateResource::getFilename', [$this, '_overridePluginTemplates']);

        Hook::add('TemplateManager::display', [$this, 'handleTemplateDisplay']);
        Hook::add('LoadHandler', [$this, 'setupHandler']);
        return true;
    }

    /****************/
    /**** Plugin ****/
    /****************/

    /**
     * @copydoc Plugin::isSitePlugin()
     */
    function isSitePlugin() {
        // This is a site-wide plugin.
        return true;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     * Get the plugin name
     */
    function getDisplayName() {
        return __('plugins.generic.downloadReviews.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     * Get the description
     */
    function getDescription() {
        return __('plugins.generic.downloadReviews.description');
    }

    /**
     * @copydoc Plugin::getInstallSitePluginSettingsFile()
     * get the plugin settings
     */
    function getInstallSitePluginSettingsFile() {
        return $this->getPluginPath() . '/settings.xml';
    }

    function handleTemplateDisplay($hookName, $params) {
//        $request = Application::get()->getRequest();
//        $context = $request->getContext();
//
//        if ($context) {
//            if ($this->isJournalManager()) {
//                $templateMgr = &$params[0];
//                $menu = $templateMgr->getState('menu');
//                $router = $request->getRouter();
//
//                $menu['statistics']['submenu']['site_usage'] = [
//                    'name' => __('plugins.generic.siteUsage.siteUsage'),
//                    'url' => $router->url($request, null, 'stats', 'siteUsage'),
//                    'isCurrent' => $request->getRequestedPage() === 'stats' && $request->getRequestedOp() === 'siteUsage'
//                ];
//
//                $templateMgr->setState(['menu' => $menu]);
//            }
//        }
    }

    function setupHandler($hookName, $params) {
//        $requestedPage = $params[0];
//        $requestedOperation = $params[1];
//
//        if ($this->isJournalManager() && $requestedPage === "stats" && $requestedOperation === "siteUsage") {
//
//            $this->import('pages.SiteUsageHandler');
//            define('HANDLER_CLASS', SiteUsageHandler::class);
//
//            return true;
//        }
//
//        return false;
    }

    function isJournalManager(): bool
    {
//        $request = Application::get()->getRequest();
//        $user = $request->getUser();
//        if(!$user) {
//            return false;
//        }
//        $context = $request->getContext();
//        if($context) {
//            $contextId = $context->getId();
//            $roleDao = DAORegistry::getDAO('RoleDAO'); /* @var $roleDao RoleDAO */
//            return $roleDao->userHasRole($contextId, $user->getId(), Role::ROLE_ID_MANAGER);
//        }
//
        return false;
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb) {
//        $router = $request->getRouter();
//        import('lib.pkp.classes.linkAction.request.AjaxModal');
//        return array_merge(
//            $this->getEnabled()?array(
//                new LinkAction(
//                    'settings',
//                    new AjaxModal(
//                        $router->url($request, null, null, 'manage', null, array('verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic')),
//                        $this->getDisplayName()
//                    ),
//                    __('manager.plugins.settings'),
//                    null
//                ),
//            ):array(),
//            parent::getActions($request, $verb)
//        );
    }

    public function manage($args, $request) {
//        switch ($request->getUserVar('verb')) {
//            case 'settings':
//                $context = $request->getContext();
//
//                $templateMgr = TemplateManager::getManager($request);
//                $templateMgr->registerPlugin('function', 'plugin_url', array($this, 'smartyPluginUrl'));
//
//                $this->import('SiteUsageSettingsForm');
//                $form = new SiteUsageSettingsForm($this, $context->getId());
//
//                if ($request->getUserVar('save')) {
//                    $form->readInputData();
//                    if ($form->validate()) {
//                        $form->execute();
//                        return new JSONMessage(true);
//                    }
//                } else {
//                    $form->initData();
//                }
//                return new JSONMessage(true, $form->fetch($request));
//        }
//        return parent::manage($args, $request);
    }
}
