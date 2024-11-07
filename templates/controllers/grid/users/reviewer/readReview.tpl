{**
 * templates/controllers/grid/users/reviewer/readReview.tpl
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Screen to let user read a review.
 *
 *}

{* Form handler attachment implemented in application-specific versions of this template. *}

{capture assign="reviewerRecommendations"}
    {include file="reviewer/review/reviewerRecommendations.tpl" description="reviewer.article.selectRecommendation.byEditor" required=false}
{/capture}

<script>
    $(function() {ldelim}
        $('#readReviewForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');

        $("#exportOptions").hide();
        $("#btnExport").click(function() {
            $("#exportOptions").toggle();
        });
        $("#readReviewForm").on('click', function(event) {
            let $target = $(event.target);
            if (!$target.closest('#exportOptions').length && !$target.is('#btnExport')) {
                $("#exportOptions").hide();
            }
        });
        {rdelim})
</script>
<div id="btnExport" class="pkpButton pkp_helpers_align_right">
    {translate key="plugins.generic.downloadReviews.downloadReviewForm"} <i class="fa fa-download"></i>
</div>
<div id="exportOptions" style="position:absolute;right:16px;margin-top:34px;z-index:1">
    <div>
        <a
            class="download-review-option"
            href="{$downloadUrl}/pdf?submissionId={$reviewAssignment->getSubmissionId()|escape}&reviewAssignmentId={$reviewAssignment->getId()}&authorFriendly=1">{translate key="plugins.generic.downloadReviews.review.authorOnly"} (PDF)
        </a>
        <a
            class="download-review-option"
            href="{$downloadUrl}/xml?submissionId={$reviewAssignment->getSubmissionId()|escape}&reviewAssignmentId={$reviewAssignment->getId()}&authorFriendly=1">{translate key="plugins.generic.downloadReviews.review.authorOnly"} (XML)
        </a>
        <a
            class="download-review-option"
            href="{$downloadUrl}/pdf?submissionId={$reviewAssignment->getSubmissionId()|escape}&reviewAssignmentId={$reviewAssignment->getId()}&authorFriendly=0"">{translate key="plugins.generic.downloadReviews.review.allSections"} (PDF)
        </a>
        <a
            class="download-review-option"
            href="{$downloadUrl}/xml?submissionId={$reviewAssignment->getSubmissionId()|escape}&reviewAssignmentId={$reviewAssignment->getId()}&authorFriendly=0"">{translate key="plugins.generic.downloadReviews.review.allSections"} (XML)
        </a>
    </div>
</div>

<form class="pkp_form" id="readReviewForm" method="post" action="{url op="reviewRead"}">
    {csrf}
    <input type="hidden" name="reviewAssignmentId" value="{$reviewAssignment->getId()|escape}" />
    <input type="hidden" name="submissionId" value="{$reviewAssignment->getSubmissionId()|escape}" />
    <input type="hidden" name="stageId" value="{$reviewAssignment->getStageId()|escape}" />


    {fbvFormSection}
        <div id="reviewAssignment-{$reviewAssignment->getId()|escape}">
            <h2>{$reviewAssignment->getReviewerFullName()|escape}</h2>
            {fbvFormSection class="description"}
            {translate key="editor.review.readConfirmation"}
            {/fbvFormSection}

            {if $reviewAssignment->getDateCompleted()}
                {if $reviewAssignment->getCompetingInterests()}
                    <h3>{translate key="reviewer.submission.competingInterests"}</h3>
                    <div class="review_competing_interests">
                        {$reviewAssignment->getCompetingInterests()|nl2br|strip_unsafe_html}
                    </div>
                {/if}

                {fbvFormSection}
                    <div class="pkp_controllers_informationCenter_itemLastEvent">
                        {translate key="common.completed.date" dateCompleted=$reviewAssignment->getDateCompleted()|date_format:$datetimeFormatShort}
                    </div>
                {/fbvFormSection}

                {if $reviewAssignment->getRecommendation()}
                    {fbvFormSection}
                        <div class="pkp_controllers_informationCenter_itemLastEvent">
                            {translate key="submission.recommendation" recommendation=$reviewAssignment->getLocalizedRecommendation()}
                        </div>
                    {/fbvFormSection}
                {/if}

                {if $reviewAssignment->getReviewFormId()}
                    {include file="reviewer/review/reviewFormResponse.tpl"}
                {elseif $comments->getCount() || $commentsPrivate->getCount()}
                    <h3>{translate key="editor.review.reviewerComments"}</h3>
                    {iterate from=comments item=comment}
                        <h4>{translate key="submission.comments.canShareWithAuthor"}</h4>
                        {include file="controllers/revealMore.tpl" content=$comment->getComments()|strip_unsafe_html}
                    {/iterate}
                    {iterate from=commentsPrivate item=comment}
                        <h4>{translate key="submission.comments.cannotShareWithAuthor"}</h4>
                        {include file="controllers/revealMore.tpl" content=$comment->getComments()|strip_unsafe_html}
                    {/iterate}
                {/if}

            {else}
                {if $reviewAssignment->getDateCompleted()}
                    <span class="pkp_controllers_informationCenter_itemLastEvent">{translate key="common.completed.date" dateCompleted=$reviewAssignment->getDateCompleted()|date_format:$datetimeFormatShort}</span>
                {elseif $reviewAssignment->getDateConfirmed()}
                    <span class="pkp_controllers_informationCenter_itemLastEvent">{translate key="common.confirmed.date" dateConfirmed=$reviewAssignment->getDateConfirmed()|date_format:$datetimeFormatShort}</span>
                {elseif $reviewAssignment->getDateReminded()}
                    <span class="pkp_controllers_informationCenter_itemLastEvent">{translate key="common.reminded.date" dateReminded=$reviewAssignment->getDateReminded()|date_format:$datetimeFormatShort}</span>
                {elseif $reviewAssignment->getDateNotified()}
                    <span class="pkp_controllers_informationCenter_itemLastEvent">{translate key="common.notified.date" dateNotified=$reviewAssignment->getDateNotified()|date_format:$datetimeFormatShort}</span>
                {elseif $reviewAssignment->getDateAssigned()}
                    <span class="pkp_controllers_informationCenter_itemLastEvent">{translate key="common.assigned.date" dateAssigned=$reviewAssignment->getDateAssigned()|date_format:$datetimeFormatShort}</span>
                {/if}
            {/if}
        </div>
    {/fbvFormSection}


    <div class="pkp_notification" id="noFilesWarning" style="display: none;">
        {include file="controllers/notification/inPlaceNotificationContent.tpl" notificationId=noFilesWarningContent notificationStyleClass=notifyWarning notificationTitle="editor.review.noReviewFilesUploaded"|translate notificationContents="editor.review.noReviewFilesUploaded.details"|translate}
    </div>

    {fbvFormArea id="readReview"}
    {fbvFormSection title="reviewer.submission.reviewerFiles"}
    {capture assign=reviewAttachmentsGridUrl}{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT component="grid.files.attachment.EditorReviewAttachmentsGridHandler" op="fetchGrid" submissionId=$submission->getId() reviewId=$reviewAssignment->getId() stageId=$reviewAssignment->getStageId() escape=false}{/capture}
    {load_url_in_div id="readReviewAttachmentsGridContainer" url=$reviewAttachmentsGridUrl}
    {/fbvFormSection}

    {$reviewerRecommendations}

    {fbvFormSection label="editor.review.rateReviewer" description="editor.review.rateReviewer.description"}
    {foreach from=$reviewerRatingOptions item="stars" key="value"}
        <label class="pkp_star_selection">
            <input type="radio" name="quality" value="{$value|escape}"{if $value == $reviewAssignment->getQuality()} checked{/if}>
            {$stars}
        </label>
    {/foreach}
    {/fbvFormSection}

    {fbvFormButtons id="closeButton" hideCancel=false submitText="common.confirm"}
    {/fbvFormArea}
</form>
<style>
    .download-review-option {
        text-decoration: none;
        font-size: 0.9rem;
        padding: 0.5rem;
        border: 1px solid #dee2e6;
        display: block;
        background-color: #fff;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    }
</style>
