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
use Illuminate\Support\Carbon;
use Mpdf\Mpdf;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
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
            $reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO'); /** @var \PKP\submission\reviewAssignment\ReviewAssignmentDAO $reviewAssignmentDao */
            $authorFriendly = (bool) $request->getUserVar('authorFriendly');
            $reviewId = $request->getUserVar('reviewAssignmentId');
            $reviewAssignment = $reviewAssignmentDao->getById($reviewId);
            $submissionId = $request->getUserVar('submissionId');
            $submission = Repo::submission()->get($submissionId);
            $submissionCommentDao = DAORegistry::getDAO('SubmissionCommentDAO'); /* @var $submissionCommentDao SubmissionCommentDAO */
            if($params[1] === 'pdf') {
                $submissionId = $submission->getId();
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

                $html = "
            <html>
            <head>
                <style>
                    body { font-family: Arial; color: rgb(41, 41, 41); }
                    h1, h2, h3, h4, h5, h6 { margin: 0; padding: 5px 0; }
                    .section { margin-bottom: 15px; }
                </style>
            </head>
            <body>
                <div class='section'>
                    <h2>" . __('editor.review') . ": $cleanTitle</h2>
                </div>
                <div class='section'>
                    <h3 style='font-weight: bold;'>" . $reviewerName . "</h3>
                </div>
        ";

                if ($dateCompleted = $reviewAssignment->getDateCompleted()) {
                    $html .= "
                <div class='section'>
                   <h4 style='font-weight: bold;'>" . __('common.completed') . ': ' . $dateCompleted . "</h4>
                </div>
            ";
                }

                if ($reviewAssignment->getRecommendation()) {
                    $recommendation = $reviewAssignment->getLocalizedRecommendation();
                    $html .= "
                <div class='section'>
                    <h4 style='font-weight: bold;'>" . __('editor.submission.recommendation') . ': ' . $recommendation . "</h4>
                </div>
            ";
                }

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
                        $html .= "
                    <div>
                        <h4 style='font-weight: bold;'>" . strip_tags($reviewFormElement->getLocalizedQuestion()) . "</h4>
                    </div>
                ";
                        if($description = $reviewFormElement->getLocalizedDescription()) {
                            $html .= "<span>" . $description . "</span>";
                        }
                        $value = $reviewFormResponses[$elementId];
                        $textFields = [
                            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_SMALL_TEXT_FIELD,
                            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_TEXT_FIELD,
                            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_TEXTAREA
                        ];
                        if (in_array($reviewFormElement->getElementType(), $textFields)) {
                            $html .= "<div class='section'><span>" . $value . "</span></div>";
                        } elseif ($reviewFormElement->getElementType() == ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES) {
                            $possibleResponses = $reviewFormElement->getLocalizedPossibleResponses();
                            $reviewFormCheckboxResponses = $reviewFormResponses[$elementId];
                            foreach ($possibleResponses as $key => $possibleResponse) {
                                if (in_array($key, $reviewFormCheckboxResponses)) {
                                    $html .= "
                                <div style='margin-bottom: 5px;'>
                                    <input type='checkbox' checked=1>
                                    <span>
                                        " . htmlspecialchars($possibleResponse) . "
                                    </span>
                                </div>
                            ";
                                } else {
                                    $html .= "
                                <div style='margin-bottom: 5px;'>
                                    <input type='checkbox'>
                                    <span>
                                        " . htmlspecialchars($possibleResponse) . "
                                    </span>
                                </div>
                            ";
                                }
                            }
                            $html .= "<div class='section'></div>";
                        } elseif ($reviewFormElement->getElementType() == ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_RADIO_BUTTONS) {
                            $possibleResponsesRadios = $reviewFormElement->getLocalizedPossibleResponses();
                            foreach ($possibleResponsesRadios as $key => $possibleResponseRadio) {
                                if($reviewFormResponses[$elementId] == $key) {
                                    $html .= "
                                <div style='margin-bottom: 5px;'>
                                    <input type='radio' checked='1'>
                                    <span>
                                        " . htmlspecialchars($possibleResponseRadio) . "
                                    </span>
                                </div>
                            ";
                                } else {
                                    $html .= "
                                <div style='margin-bottom: 5px;'>
                                    <input type='radio'>
                                    <span>
                                        " . htmlspecialchars($possibleResponseRadio) . "
                                    </span>
                                </div>
                            ";
                                }
                            }
                            $html .= "<div class='section'></div>";
                        } elseif ($reviewFormElement->getElementType() == ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_DROP_DOWN_BOX) {
                            $possibleResponsesDropdown = $reviewFormElement->getLocalizedPossibleResponses();
                            $dropdownResponse = $possibleResponsesDropdown[$reviewFormResponses[$elementId]];
                            $html .= "<div class='section'><span>" . $dropdownResponse . "</span></div>";
                        }
                    }
                } else {
                    $html .= "
                <div>
                    <h4 style='font-weight: bold;'>" . __('editor.review.reviewerComments') . "</h4>
                    <em style='font-weight: bold; color:#606060;'>" . __('submission.comments.forAuthorEditor') . "</em>
                </div>
            ";

                    if($submissionComments->records->isEmpty()) $html .= "<div class='section'><span>" . __('common.none') . "</span></div>";
                    foreach ($submissionComments->records as $comment) {
                        $commentStripped = strip_tags($comment->comments);
                        $html .= "<div class='section'><span>" . $commentStripped . "</span></div>";
                    }
                    if (!$authorFriendly) {
                        $html .= "
                    <div>
                        <em style='font-weight: bold; color:#606060;'>" . __('submission.comments.cannotShareWithAuthor') . "</em>
                    </div>
                ";

                        if($submissionCommentsPrivate->records->isEmpty()) $html .= "<div class='section'><span>" . __('common.none') . "</span></div>";
                        foreach ($submissionCommentsPrivate->records as $comment) {
                            $commentStripped = strip_tags($comment->comments);
                            $html .= "<div class='section'><span>" . $commentStripped . "</span></div>";
                        }
                    }
                }
                $submissionFiles = Repo::submissionFile()
                    ->getCollector()
                    ->filterBySubmissionIds([$submissionId])
                    ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_SUBMISSION])
                    ->getMany();

                $primaryLocale = Locale::getPrimaryLocale();
                $html .= "<div><h4 style='font-weight: bold;'>" . __('reviewer.submission.reviewFiles') . "</h4></div>";

                foreach ($submissionFiles as $submissionFile) {
                    $fileName = $submissionFile->_data['name'][$primaryLocale];
                    $html .= "<div class='section'><span>" . $fileName . "</span></div>";
                }

                $html .= "</body></html>";
                $mpdf->WriteHTML($html);
                $mpdf->Output("submission_review_{$submissionId}-{$reviewId}.pdf", 'D');
            } elseif($params[1] === 'xml') {
                $request = $this->getRequest();
                $submissionId = $request->getUserVar('submissionId');
                $reviewId = $request->getUserVar('reviewAssignmentId');
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
                $selfUri = $xml->createElement('self-uri', $request->getBaseUrl());
                $journalMeta->appendChild($selfUri);

                $front->appendChild($journalMeta);
                $articleMeta = $xml->createElement('article-meta');
                $front->appendChild($articleMeta);

                $articleId = $xml->createElement('article-id', $submissionId);
                $articleId->setAttribute('id-type', 'submission-id');
                $articleMeta->appendChild($articleId);

                $titleGroup = $xml->createElement('title-group');
                $articleMeta->appendChild($titleGroup);
                $articleTitle = $xml->createElement('article-title', $articleTitle);
                $titleGroup->appendChild($articleTitle);

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

                $eventDesc = $xml->createElement('event-desc', 'Current Submission Review Completed');
                $eventDate = $xml->createElement('date');
                $eventDate->setAttribute('iso-8601-date', $dateReviewCompleted);

                $event->appendChild($eventDesc);
                $day = $xml->createElement('day', $dateParsed->day);
                $eventDate->appendChild($day);
                $month = $xml->createElement('month', $dateParsed->month);
                $eventDate->appendChild($month);
                $year = $xml->createElement('year', $dateParsed->year);
                $eventDate->appendChild($year);
                $event->appendChild($eventDate);
                $pubHistory->appendChild($event);
                $articleMeta->append($pubHistory);

                $permissions = $xml->createElement('permissions');
                $articleMeta->appendChild($permissions);

                $licenseRef = $xml->createElement('ali:license_ref', 'http://creativecommons.org/licenses/by/4.0/');
                $permissions->appendChild($licenseRef);

                $submissionCommentDao = DAORegistry::getDAO('SubmissionCommentDAO'); /* @var $submissionCommentDao SubmissionCommentDAO */
                $submissionComments = $submissionCommentDao->getReviewerCommentsByReviewerId($submissionId, $reviewAssignment->getReviewerId(), $reviewId, true);

                $customMetaGroupObject = $xml->createElement('custom-meta-group');
                $customMetaPeerReviewStage = $xml->createElement('custom-meta');
                $peerReviewStageTag = $xml->createElement('meta-name', 'peer-review-stage');
                $peerReviewStageValueTag = $xml->createElement('meta-value', 'pre-publication');

                $customMetaPeerReviewStage->appendChild($peerReviewStageTag);
                $customMetaPeerReviewStage->appendChild($peerReviewStageValueTag);

                $customMetaReccomObject = $xml->createElement('custom-meta');
                $recomTag = $xml->createElement('meta-name', 'peer-review-recommendation');
                $recomValueTag = $xml->createElement('meta-value', $recommendation);

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
                        $nameTag = $xml->createElement('meta-name', strip_tags($reviewFormElement->getLocalizedQuestion()));
                        $valueTag = $xml->createElement('meta-value', $answer);
                        $customMetaObject->appendChild($nameTag);
                        $customMetaObject->appendChild($valueTag);
                        $customMetaGroupObject->appendChild($customMetaObject);
                    }
                } else {
                    foreach ($submissionComments->records as $key => $comment) {
                        $customMetaCommentsObject = $xml->createElement('custom-meta');
                        $metaName = $submissionComments->records->count() > 1 ? 'submission-comments-' . $key + 1 : 'submission-comments';
                        $commentsTag = $xml->createElement('meta-name', $metaName);
                        $commentsValueTag = $xml->createElement('meta-value', strip_tags($comment->comments));
                        $customMetaCommentsObject->appendChild($commentsTag);
                        $customMetaCommentsObject->appendChild($commentsValueTag);
                        $customMetaGroupObject->appendChild($customMetaCommentsObject);
                    }

                    if(!$authorFriendly) {
                        $submissionCommentsPrivate = $submissionCommentDao->getReviewerCommentsByReviewerId($submissionId, $reviewAssignment->getReviewerId(), $reviewId, false);
                        foreach ($submissionCommentsPrivate->records as $key => $comment) {
                            $customMetaCommentsPrivateObject = $xml->createElement('custom-meta');
                            $metaName = $submissionCommentsPrivate->records->count() > 1 ? 'submission-comments-private-' . $key + 1 : 'submission-comments-private';
                            $commentsTag = $xml->createElement('meta-name', $metaName);
                            $commentsValueTag = $xml->createElement('meta-value', strip_tags($comment->comments));
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

        if($reviewAssignment->getSubmissionId() != $submissionId) {
            throw new Exception('Invalid review submission or review assignment');
        }

        return true;
    }

}
