<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2021 Edward Chernenko.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
*/

/**
 * @file
 * Hooks related to normal edits.
 */

use MediaWiki\ChangeTags\Hook\ListDefinedTagsHook;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\EditPage__showEditForm_fieldsHook;
use MediaWiki\Moderation\AddLogEntryConsequence;
use MediaWiki\Moderation\EditFormOptions;
use MediaWiki\Moderation\Hook\HookRunner;
use MediaWiki\Moderation\IConsequenceManager;
use MediaWiki\Moderation\InvalidatePendingTimeCacheConsequence;
use MediaWiki\Moderation\MarkAsMergedConsequence;
use MediaWiki\Moderation\QueueEditConsequence;
use MediaWiki\Moderation\TagRevisionAsMergedConsequence;
use MediaWiki\Revision\RenderedRevision;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\Hook\MultiContentSaveHook;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\User\UserIdentity;

class ModerationEditHooks implements
	BeforePageDisplayHook,
	EditPage__showEditForm_fieldsHook,
	ListDefinedTagsHook,
	MultiContentSaveHook,
	PageSaveCompleteHook
{
	/** @var IConsequenceManager */
	protected $consequenceManager;

	/** @var ModerationCanSkip */
	protected $canSkip;

	/** @var EditFormOptions */
	protected $editFormOptions;

	/** @var HookRunner */
	protected $hookRunner;

	/**
	 * @param IConsequenceManager $consequenceManager
	 * @param ModerationCanSkip $canSkip
	 * @param EditFormOptions $editFormOptions
	 * @param HookRunner $hookRunner
	 */
	public function __construct(
		IConsequenceManager $consequenceManager,
		ModerationCanSkip $canSkip,
		EditFormOptions $editFormOptions,
		HookRunner $hookRunner
	) {
		$this->consequenceManager = $consequenceManager;
		$this->canSkip = $canSkip;
		$this->editFormOptions = $editFormOptions;
		$this->hookRunner = $hookRunner;
	}

	/**
	 * MultiContentSave hook handler.
	 * Intercept normal edits and queue them for moderation.
	 * @param RenderedRevision $renderedRevision
	 * @param UserIdentity $user
	 * @param CommentStoreComment $summary
	 * @param int $flags
	 * @param Status $status
	 * @return bool
	 */
	public function onMultiContentSave( $renderedRevision, $user, $summary, $flags, $status ) {
		$rev = $renderedRevision->getRevision();
		$page = ModerationCompatTools::makeWikiPage( $rev->getPageAsLinkTarget() );
		$user = User::newFromIdentity( $user );

		$title = $page->getTitle();
		if ( $this->canSkip->canEditSkip( $user, $title->getNamespace() ) ) {
			return true;
		}

		$summary = $summary->text;
		$content = $rev->getSlot( SlotRecord::MAIN )->getContent(); // TODO: support non-main slot edits
		$is_minor = $flags & EDIT_MINOR;

		/*
		 * Allow third-party extension to monitor edits that are about to be intercepted by Moderation.
		 * If this hook returns false, then Moderation won't intercept this edit.
		 */
		if ( !$this->hookRunner->onModerationIntercept(
			$page, $user, $content, $summary, $is_minor, null, null, $flags, $status
		) ) {
			return true;
		}

		/* Some extensions (e.g. Extension:Flow) use customized ContentHandlers.
			They need special handling for Moderation to intercept them properly.

			For example, Flow first creates a comments page and then a comment,
			but if edit in the comments page was sent to moderation, Flow will
			report error because this comments page was not found.

			Unless we add support for the non-standard ContentHandler,
			edits to pages with it can't be queued for moderation.

			NOTE: edits to Flow discussions will bypass moderation.
		*/
		$handler = $page->getContentHandler();
		if ( !( $handler instanceof TextContentHandler ) ) {
			return true;
		}

		global $wgCommentStreamsNamespaceIndex;
		if ( $wgCommentStreamsNamespaceIndex && $title->getNamespace() == $wgCommentStreamsNamespaceIndex ) {
			// Edits in discussions of Extension:CommentStreams will bypass moderation,
			// because CommentStreams treats "edit queued for moderation" as an error.
			// This can only be fixed in Extension:CommentStreams itself.
			return true;
		}

		$this->consequenceManager->add( new QueueEditConsequence(
			$page, $user, $content, $summary,
			$this->editFormOptions->getSection(),
			$this->editFormOptions->getSectionText(),
			(bool)( $flags & EDIT_FORCE_BOT ),
			(bool)$is_minor
		) );

		/* Watch/Unwatch the page immediately:
			watchlist is the user's own business, no reason to wait for approval of the edit */
		$this->editFormOptions->watchIfNeeded( $user, [ $title ] );

		/*
			We have queued this edit for moderation.
			No need to save anything at this point.
			Later (if approved) the edit will be saved via doEditContent().

			Here we just redirect the users back to the page they edited
			(as was the behavior for unmoderated edits).
			Notification "Your edit was successfully sent to moderation"
			will be shown by JavaScript.
		*/
		$out = RequestContext::getMain()->getOutput();
		$out->redirect( $this->getRedirectURL( $title, $out ) );

		$status->fatal( 'moderation-edit-queued' );
		return false;
	}

	/**
	 * Returns the URL to where the user is redirected after successful edit.
	 * @param Title $title Article that was edited.
	 * @param IContextSource $context Any object that contains current context.
	 * @return string URL
	 */
	protected function getRedirectURL( Title $title, IContextSource $context ) {
		$query = [ 'modqueued' => 1 ];

		/* Are customized "continue editing" links needed?
			E.g. Special:FormEdit or ?action=formedit from Extension:PageForms. */
		$returnto = '';
		$returntoquery = [];
		$this->hookRunner->onModerationContinueEditingLink( $returnto, $returntoquery, $title, $context );

		if ( $returnto || $returntoquery ) {
			/* Pack into one parameter to simplify the JavaScript part. */
			$query['returnto'] = FormatJson::encode( [
				$returnto,
				$returntoquery
			] );
		}

		return $title->getFullURL( $query );
	}

	/**
	 * BeforePageDisplay hook handler.
	 * @param OutputPage $out
	 * @param Skin $skin @phan-unused-param
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$isAutomoderated = $this->canSkip->canEditSkip(
			$out->getUser(),
			$out->getTitle()->getNamespace()
		);
		if ( !$isAutomoderated ) {
			$out->addModules( [
				'ext.moderation.notify',
				'ext.moderation.notify.desktop'
			] );
			ModerationAjaxHook::add( $out );
		}
	}

	/**
	 * PageSaveComplete hook handler.
	 * If this is a merged edit, then 'wpMergeID' is the ID of moderation entry.
	 * Here we mark this entry as merged.
	 *
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $user
	 * @param string $summary @phan-unused-param
	 * @param int $flags @phan-unused-param
	 * @param RevisionRecord $revisionRecord
	 * @param EditResult $editResult @phan-unused-param
	 * @return true
	 */
	public function onPageSaveComplete(
		$wikiPage, $user, $summary, $flags, $revisionRecord, $editResult
	) {
		if ( !$revisionRecord ) {
			// Double edit - nothing to do on the second time
			return true;
		}

		$this->markAsMergedIfNeeded(
			$wikiPage,
			$revisionRecord->getId(),
			User::newFromIdentity( $user )
		);
		return true;
	}

	/**
	 * Mark the revision as "merged edit" if this edit was made via modaction=merge.
	 * @param WikiPage $wikiPage
	 * @param int $revid
	 * @param User $user
	 */
	protected function markAsMergedIfNeeded( $wikiPage, $revid, $user ) {
		/* Only moderators can merge. If someone else adds wpMergeID to the edit form, ignore it */
		if ( !$user->isAllowed( 'moderation' ) ) {
			return;
		}

		$mergeID = RequestContext::getMain()->getRequest()->getInt( 'wpMergeID' );
		if ( !$mergeID ) {
			return;
		}

		$manager = $this->consequenceManager;
		$somethingChanged = $manager->add( new MarkAsMergedConsequence( $mergeID, $revid ) );

		if ( $somethingChanged ) {
			$manager->add( new AddLogEntryConsequence( 'merge', $user, $wikiPage->getTitle(), [
				'modid' => $mergeID,
				'revid' => $revid
			] ) );

			/* Clear the cache of "Most recent mod_timestamp of pending edit"
				- could have changed */
			$manager->add( new InvalidatePendingTimeCacheConsequence() );

			/* Tag this edit as "manually merged" */
			$manager->add( new TagRevisionAsMergedConsequence( $revid ) );
		}
	}

	/**
	 * EditPage::showEditForm:fields hook handler.
	 * Add wpMergeID field to edit form when moderator is doing a manual merge.
	 * @param EditPage $editpage @phan-unused-param
	 * @param OutputPage $out
	 * @return true
	 */
	// phpcs:ignore MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	public function onEditPage__showEditForm_fields( $editpage, $out ) {
		$mergeID = $this->editFormOptions->getMergeID();
		if ( $mergeID ) {
			$out->addHTML( Html::hidden( 'wpMergeID', (string)$mergeID ) );
			$out->addHTML( Html::hidden( 'wpIgnoreBlankSummary', '1' ) );
		}

		return true;
	}

	/**
	 * ListDefinedTags hook handler.
	 * Registers 'moderation-merged' ChangeTag.
	 * @param string[] &$tags
	 * @return true
	 */
	public function onListDefinedTags( &$tags ) {
		$tags[] = 'moderation-merged';
		return true;
	}
}
