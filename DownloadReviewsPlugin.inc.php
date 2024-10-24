<?php

/**
 * @file plugins/generic/downloadReviews/DownloadReviewsPlugin.inc.php
 *
 * @class DownloadReviewsPlugin
 * @ingroup plugins_generic_DownloadReviewsPlugin
 *
 * @brief DownloadReviews plugin class
 */

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use PKP\db\DAORegistry;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

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
        Hook::add('TemplateManager::fetch', [$this, 'editTemplate']);
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

    public function editTemplate($hookName, $params)
    {
        if ($params[1] == 'controllers/grid/users/reviewer/readReview.tpl') {
            $templateMgr =& $params[0];
            $request = Application::get()->getRequest();
            $templateMgr->assign('downloadLink', $request->getIndexUrl() . '/' . $request->getContext()->getPath() . '/exportreview');
        }
    }

    /**
     * @throws Exception
     */
    function setupHandler($hookName, $params) {
        $request = Application::get()->getRequest();
        if($params[0] === 'exportreview' && $this->validateReviewExport($request)) {
            if($params[1] === 'pdf') {

            } elseif($params[1] === 'xml') {

            }
        }

        return false;
    }

    /**
     * @throws Exception
     */
    protected function validateReviewExport(Request $request): bool
    {
        $reviewId = $request->getUserVar('reviewAssignmentId');
        $reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO'); /** @var \PKP\submission\reviewAssignment\ReviewAssignmentDAO $reviewAssignmentDao */
        $reviewAssignment = $reviewAssignmentDao->getById($reviewId);

        $user = $request->getUser();
        if(!$user) {
            return false;
        }

        $context = $request->getContext();
        if($context) {
            $contextId = $context->getId();
            $roleDao = DAORegistry::getDAO('RoleDAO'); /* @var $roleDao RoleDAO */

            if(!$roleDao->userHasRole($contextId, $user->getId(), Role::ROLE_ID_MANAGER)
                && $roleDao->userHasRole($contextId, $user->getId(), Role::ROLE_ID_MANAGER))
            {
                return false;
            }
        }

        if(!in_array($request->getUserVar('authorFriendly'), ['0', '1'])) {
            throw new Exception('Invalid authorFriendly value');
        }

        $submissionId = $request->getUserVar('submissionId');
        $submission = Repo::submission()->get($submissionId);
        if (!$submission) {
            throw new Exception('Invalid submission');
        }

        if(!$reviewAssignment) {
            throw new Exception('Invalid review assignment');
        }

        return true;
    }

}
