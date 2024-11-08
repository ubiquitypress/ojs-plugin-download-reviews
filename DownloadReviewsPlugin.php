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
use APP\template\TemplateManager;
use Illuminate\Support\Carbon;
use Mpdf\Mpdf;
use PKP\facades\Locale;
use PKP\db\DAORegistry;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\reviewForm\ReviewFormElement;
use PKP\reviewForm\ReviewFormElementDAO;
use PKP\reviewForm\ReviewFormResponseDAO;
use PKP\security\Role;
use PKP\submission\SubmissionCommentDAO;
use PKP\submissionFile\SubmissionFile;

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
        Hook::add('LoadComponentHandler', [$this, 'setupGridHandler']);

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
            $request = Application::get()->getRequest();
            $templateMgr = &$params[0];
            $templateMgr->assign('downloadUrl', $request->url(null, 'reviewsHandler', 'download'));
        }
    }

    /**
     * @throws Exception
     */
    function setupGridHandler($hookName, $params) {
        $request = Application::get()->getRequest();
        if($params[0] === 'reviews.DownloadHandler' && $this->validateReviewExport($request)) {
            $reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO'); /** @var \PKP\submission\reviewAssignment\ReviewAssignmentDAO $reviewAssignmentDao */
            $authorFriendly = (bool) $request->getUserVar('authorFriendly');
            $reviewId = (int) $request->getUserVar('reviewAssignmentId');
            $reviewAssignment = $reviewAssignmentDao->getById($reviewId);
            $submissionId = (int) $request->getUserVar('submissionId');
            $submission = Repo::submission()->get($submissionId);
            $submissionCommentDao = DAORegistry::getDAO('SubmissionCommentDAO'); /* @var $submissionCommentDao SubmissionCommentDAO */
            if($params[1] === 'pdf') {
                $submissionComments = $submissionCommentDao->getReviewerCommentsByReviewerId($submissionId, $reviewAssignment->getReviewerId(), $reviewId, true);
                $submissionCommentsPrivate = $submissionCommentDao->getReviewerCommentsByReviewerId($submissionId, $reviewAssignment->getReviewerId(), $reviewId, false);
                $title = $submission->getCurrentPublication()->getLocalizedTitle(null, 'html');
                $cleanTitle = str_replace("&nbsp;", " ", strip_tags($title));
                require_once 'vendor/autoload.php';
                $mpdf = new Mpdf([
                    'default_font' => 'NotoSansSC',
                    'mode' => '+aCJK',
                    "autoScriptToLang" => true,
                    "autoLangToFont" => true,
                ]);

                if($authorFriendly) {
                    $reviewAssignments = $reviewAssignmentDao->getBySubmissionId($submission->getId());
                    $alphabet = range('A', 'Z');
                    $reviewerLetter = "";
                    $i = 0;
                    foreach($reviewAssignments as $submissionReviewAssignment) {
                        if($reviewAssignment->getReviewerId() === $submissionReviewAssignment->getReviewerId()) {
                            $reviewerLetter = $alphabet[$i];
                        }
                        $i++;
                    }
                    $reviewerName = __('user.role.reviewer') . ": $reviewerLetter";
                } else {
                    $reviewerName = __('user.role.reviewer') . ": " .  $reviewAssignment->getReviewerFullName();
                }

                $templateMgr = TemplateManager::getManager(Application::get()->getRequest());
                $submissionFiles = Repo::submissionFile()
                    ->getCollector()
                    ->filterBySubmissionIds([$submissionId])
                    ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_SUBMISSION])
                    ->getMany();

                $templateMgr->assign(
                    [
                        'cleanTitle' => $cleanTitle,
                        'reviewerName' => $reviewerName,
                        'dateCompleted' => $reviewAssignment->getDateCompleted(),
                        'recommendation' => $reviewAssignment->getLocalizedRecommendation(),
                        'submissionComments' => $submissionComments->toIterator(),
                        'authorFriendly' => $authorFriendly,
                        'submissionCommentsPrivate' => $submissionCommentsPrivate->toIterator(),
                        'submissionFiles' => $submissionFiles,
                    ]
                );

                if ($reviewAssignment->getReviewFormId()) {
                    $reviewFormElementDao = DAORegistry::getDAO('ReviewFormElementDAO');
                    /* @var $reviewFormElementDao ReviewFormElementDAO */
                    $reviewFormElements = $reviewFormElementDao->getByReviewFormId($reviewAssignment->getReviewFormId());

                    /* @var $reviewFormResponseDao ReviewFormResponseDAO */
                    $reviewFormResponseDao = DAORegistry::getDAO('ReviewFormResponseDAO');
                    $reviewFormResponses = $reviewFormResponseDao->getReviewReviewFormResponseValues($reviewAssignment->getId());
                    $templateMgr->assign([
                        'reviewFormElements' => $reviewFormElements->toIterator(),
                        'reviewFormResponses' => $reviewFormResponses,
                    ]);
                }

                $reviewHtml = $templateMgr->fetch($this->getTemplateResource('reviewDownload.tpl'));
                $mpdf->WriteHTML($reviewHtml);
                $mpdf->Output("submission_review_{$submissionId}-{$reviewId}.pdf", 'D');
            } elseif($params[1] === 'xml') {
                $request = $this->getRequest();
                $xmlFileName = "submission_review_{$submissionId}-{$reviewId}.xml";
                $submission = Repo::submission()->get($submissionId);
                $publication = $submission->getCurrentPublication();
                $htmlTitle = $publication->getLocalizedTitle(null, 'html');
                $articleTitle = $this->mapTitleHtmlTagsToXml($htmlTitle);
                $reviewAssignment = $reviewAssignmentDao->getById($reviewId);
                $recommendation = $reviewAssignment->getLocalizedRecommendation();
                $impl = new DOMImplementation();
                $doctype = $impl->createDocumentType('article',
                    '-//NLM//DTD JATS (Z39.96) Journal Archiving and Interchange DTD v1.2 20190208//EN',
                    'JATS-archivearticle1.dtd');

                $xml = $impl->createDocument(null, '', $doctype);
                $xml->encoding = 'UTF-8';
                $article = $xml->createElement('article');
                $article->setAttribute('article-type', 'reviewer-report');
                $article->setAttribute('dtd-version', '1.2');
                $article->setAttribute('xmlns:ali', 'http://www.niso.org/schemas/ali/1.0/');
                $article->setAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');
                $xml->appendChild($article);

                $front = $xml->createElement('front');
                $article->appendChild($front);

                $journalMeta = $xml->createElement('journal-meta');
                $selfUri = $xml->createElement('self-uri');
                $baseUrl = $xml->createTextNode($request->getBaseUrl());
                $selfUri->appendChild($baseUrl);
                $journalMeta->appendChild($selfUri);

                $front->appendChild($journalMeta);
                $articleMeta = $xml->createElement('article-meta');
                $front->appendChild($articleMeta);

                $submissionIdText = $xml->createTextNode($submissionId);
                $articleId = $xml->createElement('article-id');
                $articleId->setAttribute('id-type', 'submission-id');
                $articleId->appendChild($submissionIdText);
                $articleMeta->appendChild($articleId);

                $titleGroup = $xml->createElement('title-group');
                $articleMeta->appendChild($titleGroup);
                $articleTitleElem = $xml->createElement('article-title');
                $articleTitleText = $xml->createTextNode($articleTitle);
                $articleTitleElem->appendChild($articleTitleText);
                $titleGroup->appendChild($articleTitleElem);

                $contribGroup = $xml->createElement('contrib-group');
                $articleMeta->appendChild($contribGroup);

                $contrib = $xml->createElement('contrib');
                $contrib->setAttribute('contrib-type', 'author');
                $contribGroup->appendChild($contrib);

                if($authorFriendly) {
                    $reviewAssignments = $reviewAssignmentDao->getBySubmissionId($submission->getId());
                    $alphabet = range('A', 'Z');
                    $reviewerLetter = "";
                    $i = 0;
                    foreach($reviewAssignments as $submissionReviewAssignment) {
                        if($reviewAssignment->getReviewerId() === $submissionReviewAssignment->getReviewerId()) {
                            $reviewerLetter = $alphabet[$i];
                        }
                        $i++;
                    }
                    $reviewerName = __('user.role.reviewer') . ": $reviewerLetter";
                    $anonymous = $xml->createElement('anonymous');
                    $contrib->appendChild($anonymous);
                } else {
                    $reviewerName = __('user.role.reviewer') . ": " .  $reviewAssignment->getReviewerFullName();
                }

                $role = $xml->createElement('role', $reviewerName);
                $role->setAttribute('specific-use', 'reviewer');
                $contrib->appendChild($role);

                $pubHistory = $xml->createElement('pub-history');
                $event = $xml->createElement('event');
                $event->setAttribute('event-type', 'current-submission-review-completed');

                $dateReviewCompleted = $reviewAssignment->getDateCompleted();
                $dateParsed = Carbon::parse($dateReviewCompleted);

                $eventDesc = $xml->createElement('event-desc');
                $eventDescText = $xml->createTextNode('Current Submission Review Completed');
                $eventDesc->appendChild($eventDescText);
                $eventDate = $xml->createElement('date');
                $eventDate->setAttribute('iso-8601-date', $dateReviewCompleted);

                $event->appendChild($eventDesc);
                $day = $xml->createElement('day');
                $dayText = $xml->createTextNode($dateParsed->day);
                $day->appendChild($dayText);

                $eventDate->appendChild($day);
                $month = $xml->createElement('month');
                $monthText = $xml->createTextNode($dateParsed->month);
                $month->appendChild($monthText);

                $eventDate->appendChild($month);
                $year = $xml->createElement('year');
                $yearText = $xml->createTextNode($dateParsed->year);
                $year->appendChild($yearText);

                $eventDate->appendChild($year);
                $event->appendChild($eventDate);
                $pubHistory->appendChild($event);
                $articleMeta->append($pubHistory);

                $permissions = $xml->createElement('permissions');
                $articleMeta->appendChild($permissions);

                $licenseRef = $xml->createElement('ali:license_ref');
                $licenseRefText = $xml->createTextNode('http://creativecommons.org/licenses/by/4.0/');
                $licenseRef->appendChild($licenseRefText);
                $permissions->appendChild($licenseRef);

                $submissionCommentDao = DAORegistry::getDAO('SubmissionCommentDAO'); /* @var $submissionCommentDao SubmissionCommentDAO */
                $submissionComments = $submissionCommentDao->getReviewerCommentsByReviewerId($submissionId, $reviewAssignment->getReviewerId(), $reviewId, true);

                $customMetaGroupObject = $xml->createElement('custom-meta-group');
                $customMetaPeerReviewStage = $xml->createElement('custom-meta');
                $peerReviewStageTag = $xml->createElement('meta-name');
                $peerReviewStageText = $xml->createTextNode('peer-review-stage');
                $peerReviewStageTag->appendChild($peerReviewStageText);

                $peerReviewStageValueTag = $xml->createElement('meta-value');
                $peerReviewStageValueText = $xml->createTextNode('pre-publication');
                $peerReviewStageValueTag->appendChild($peerReviewStageValueText);

                $customMetaPeerReviewStage->appendChild($peerReviewStageTag);
                $customMetaPeerReviewStage->appendChild($peerReviewStageValueTag);

                $customMetaReccomObject = $xml->createElement('custom-meta');
                $recomTag = $xml->createElement('meta-name');
                $reccomTagText = $xml->createTextNode('peer-review-recommendation');
                $recomTag->appendChild($reccomTagText);

                $recomValueTag = $xml->createElement('meta-value');
                $recomValueText = $xml->createTextNode($recommendation);
                $recomValueTag->appendChild($recomValueText);
                $customMetaReccomObject->appendChild($recomTag);
                $customMetaReccomObject->appendChild($recomValueTag);

                if ($reviewAssignment->getReviewFormId()) {
                    $reviewFormElementDao = DAORegistry::getDAO('ReviewFormElementDAO');
                    /* @var $reviewFormElementDao ReviewFormElementDAO */
                    $reviewFormResponseDao = DAORegistry::getDAO('ReviewFormResponseDAO');
                    /* @var $reviewFormResponseDao ReviewFormResponseDAO */
                    $reviewFormResponses = $reviewFormResponseDao->getReviewReviewFormResponseValues($reviewAssignment->getId());
                    $reviewFormElements = $reviewFormElementDao->getByReviewFormId($reviewAssignment->getReviewFormId());
                    while ($reviewFormElement = $reviewFormElements->next()) {
                        if ($authorFriendly && !$reviewFormElement->getIncluded()) continue;
                        $elementId = $reviewFormElement->getId();
                        if ($reviewFormElement->getElementType() == ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES) {
                            $results = [];
                            foreach ($reviewFormResponses[$elementId] as $index) {
                                if (isset($reviewFormElement->getLocalizedPossibleResponses()[$index])) {
                                    $results[] = $reviewFormElement->getLocalizedPossibleResponses()[$index];
                                }
                            }
                            $answer = implode(', ', $results);
                        } elseif (in_array($reviewFormElement->getElementType(), [ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_RADIO_BUTTONS, ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_DROP_DOWN_BOX])) {
                            $possibleResponses = $reviewFormElement->getLocalizedPossibleResponses();
                            $answer = array_key_exists($reviewFormResponses[$elementId], $possibleResponses) ? $possibleResponses[$reviewFormResponses[$elementId]] : '';
                        } else {
                            $answer = $reviewFormResponses[$elementId];
                        }
                        $customMetaObject = $xml->createElement('custom-meta');
                        $nameTag = $xml->createElement('meta-name');
                        $nameText = $xml->createTextNode(strip_tags($reviewFormElement->getLocalizedQuestion()));
                        $nameTag->appendChild($nameText);

                        $valueTag = $xml->createElement('meta-value');
                        $valueText = $xml->createTextNode($answer);
                        $valueTag->appendChild($valueText);

                        $customMetaObject->appendChild($nameTag);
                        $customMetaObject->appendChild($valueTag);
                        $customMetaGroupObject->appendChild($customMetaObject);
                    }
                } else {
                    foreach ($submissionComments->records as $key => $comment) {
                        $customMetaCommentsObject = $xml->createElement('custom-meta');
                        $metaName = $submissionComments->records->count() > 1 ? 'submission-comments-' . $key + 1 : 'submission-comments';
                        $commentsTag = $xml->createElement('meta-name');
                        $commentsTagText = $xml->createTextNode($metaName);
                        $commentsTag->appendChild($commentsTagText);
                        $commentsValueTag = $xml->createElement('meta-value');
                        $commentsValueText = $xml->createTextNode(strip_tags($comment->comments));
                        $commentsValueTag->appendChild($commentsValueText);
                        $customMetaCommentsObject->appendChild($commentsTag);
                        $customMetaCommentsObject->appendChild($commentsValueTag);
                        $customMetaGroupObject->appendChild($customMetaCommentsObject);
                    }

                    if(!$authorFriendly) {
                        $submissionCommentsPrivate = $submissionCommentDao->getReviewerCommentsByReviewerId($submissionId, $reviewAssignment->getReviewerId(), $reviewId, false);
                        foreach ($submissionCommentsPrivate->records as $key => $commentPriavte) {
                            $customMetaCommentsPrivateObject = $xml->createElement('custom-meta');
                            $metaName = $submissionCommentsPrivate->records->count() > 1 ? 'submission-comments-private-' . $key + 1 : 'submission-comments-private';
                            $commentsTag = $xml->createElement('meta-name');
                            $commentsTagText = $xml->createTextNode($metaName);
                            $commentsTag->appendChild($commentsTagText);
                            $commentsValueTag = $xml->createElement('meta-value');
                            $commentsValueText = $xml->createTextNode(strip_tags($commentPriavte->comments));
                            $commentsValueTag->appendChild($commentsValueText);
                            $customMetaCommentsPrivateObject->appendChild($commentsTag);
                            $customMetaCommentsPrivateObject->appendChild($commentsValueTag);
                            $customMetaGroupObject->appendChild($customMetaCommentsPrivateObject);
                        }
                    }
                }
                $customMetaGroupObject->appendChild($customMetaPeerReviewStage);
                $customMetaGroupObject->appendChild($customMetaReccomObject);
                $articleMeta->appendChild($customMetaGroupObject);
                $xml->formatOutput = true;
                header('Content-Type: application/xml');
                header('Content-Disposition: attachment; filename="' . basename($xmlFileName) . '"');
                echo $xml->saveXML();
                exit;
            }
        }

        return false;
    }

    /**
     * Map the specific HTML tags in title/ sub title for JATS schema compability
     * @see https://jats.nlm.nih.gov/publishing/0.4/xsd/JATS-journalpublishing0.xsd
     *
     * @param  string $htmlTitle The submission title/sub title as in HTML
     * @return string
     */
    public static function mapTitleHtmlTagsToXml(string $htmlTitle): string
    {
        $mappings = [
            '<b>' 	=> '<bold>',
            '</b>' 	=> '</bold>',
            '<i>' 	=> '<italic>',
            '</i>' 	=> '</italic>',
            '<u>' 	=> '<underline>',
            '</u>' 	=> '</underline>',
        ];

        return str_replace(array_keys($mappings), array_values($mappings), $htmlTitle);
    }

    /**
     * @throws Exception
     */
    protected function validateReviewExport(Request $request): bool
    {
        $reviewId = $request->getUserVar('reviewAssignmentId');
        $user = $request->getUser();
        if(!$user) {
            return false;
        }

        if(!in_array($request->getUserVar('authorFriendly'), ['0', '1'])) {
            throw new Exception('Invalid authorFriendly value');
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

            $submissionId = $request->getUserVar('submissionId');
            $submission = Repo::submission()->get($submissionId, $contextId);
            if (!$submission) {
                throw new Exception('Invalid submission');
            }

            $reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO'); /** @var \PKP\submission\reviewAssignment\ReviewAssignmentDAO $reviewAssignmentDao */
            $reviewAssignment = $reviewAssignmentDao->getById($reviewId);

            if(!$reviewAssignment) {
                throw new Exception('Invalid review assignment');
            }

            if($reviewAssignment->getSubmissionId() != $submissionId) {
                throw new Exception('Invalid review submission or review assignment');
            }
        } else {
            return false;
        }

        return true;
    }

}
