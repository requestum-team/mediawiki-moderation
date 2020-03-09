<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020 Edward Chernenko.

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
 * Unit test of InstallApproveHookConsequence.
 */

use MediaWiki\Moderation\InstallApproveHookConsequence;

/**
 * @group Database
 */
class InstallApproveHookConsequenceTest extends MediaWikiTestCase {
	/** @var string[] */
	protected $tablesUsed = [ 'revision', 'page', 'user', 'recentchanges', 'cu_changes',
		'change_tag', 'logging', 'log_search' ];

	/**
	 * Verify that InstallApproveHookConsequence works with one edit.
	 * @covers MediaWiki\Moderation\InstallApproveHookConsequence
	 */
	public function testOneEdit() {
		$this->runApproveHookTest( [ [
			'UTPage-' . rand( 0, 100000 ),
			'127.0.0.1',
			'edit',
			[
				'ip' => '10.11.12.13',
				'xff' => '10.20.30.40',
				'ua' => 'Some-User-Agent/1.2.3',
				'tags' => "Sample tag 1\nSample tag 2",
				'timestamp' => wfTimestamp( TS_MW, (int)wfTimestamp() - 100000 )
			]
		] ] );
	}

	/**
	 * Verify that InstallApproveHookConsequence modifies rev_timestamp, etc. according to $task.
	 * @covers MediaWiki\Moderation\InstallApproveHookConsequence
	 */
	public function testInstallApproveHook() {
		// TODO: now that $this->runApproveHookTest() method exists,
		// split this test into more specific tests: editing only 1 page, with DeferredUpdates,
		// without DeferredUpdates, situation where CASE ... WHEN ... THEN is used,
		// situation where rev_timestamp is ignored,
		// situation where tags exist and don't exist,
		// situation where some edits don't need ApproveHook installed and must be unchanged, etc.

		$pageNames = [
			'UTPage1-' . rand( 0, 100000 ),
			'Project:UTPage 2-' . rand( 0, 100000 ),
			'UTPage 3-' . rand( 0, 100000 )
		];
		$usernames = [
			'Test user1 ' . rand( 0, 100000 ),
			'Test user2 ' . rand( 0, 100000 ),
			'Test user3 ' . rand( 0, 100000 )
		];

		// TODO: while testing installed InstallApproveHookConsequence followed by multiple edits,
		// also test that ApproveHook doesn't affect edits of another $title OR $user OR $type.

		// TODO: currently timestamps are ordered from older to newer on purpose,
		// because changing ApproveHook purposely ignores rev_timestamp if it is earlier
		// than timestamp of already existing revision in this page.
		// However, this behavior should be tested too!
		// Provide an array of fixed (non-randomized) timestamps which would check exactly that.
		$timestamp = wfTimestamp( TS_MW, (int)wfTimestamp() - 100000 );
		$type = ModerationNewChange::MOD_TYPE_EDIT;

		$todo = [];
		foreach ( $pageNames as $pageName ) {
			foreach ( $usernames as $username ) {
				$timestamp = wfTimestamp( TS_MW, (int)wfTimestamp( TS_UNIX, $timestamp ) + rand( 0, 12345 ) );
				$task = [
					'ip' => '10.11.' . rand( 0, 255 ) . '.' . rand( 0, 254 ),
					'xff' => '10.20.' . rand( 0, 255 ) . '.' . rand( 0, 254 ),
					'ua' => 'Some-User-Agent/1.0.' . rand( 0, 100000 ),

					// TODO: test some changes WITHOUT any tags.
					'tags' => implode( "\n", [
						"Sample tag 1 " . rand( 0, 100000 ),
						"Sample tag 2 " . rand( 0, 100000 ),
					] ),
					'timestamp' => $timestamp
				];

				$todo[] = [ $pageName, $username, $type, $task ];
			}
		}

		$this->runApproveHookTest( $todo );
	}

	/**
	 * Run the ApproveHook test with selected list of edits.
	 * For each edit, Title, User and type ("edit" or "move") must be specified,
	 * and also optional $task for ApproveHook itself (if null, then ApproveHook is NOT installed).
	 *
	 * @param array $todo
	 * @phan-param list<array{0:string,1:string,2:string,3:?array<string,string>}> $todo
	 */
	private function runApproveHookTest( array $todo ) {
		// Convert pagename and username parameters (#0 and #1) to Title/User objects
		$todo = array_map( function ( $testParameters ) {
			list( $pageName, $username, $type, $task ) = $testParameters;
			$title = Title::newFromText( $pageName );

			$user = User::isIP( $username ) ?
				User::newFromName( $username, false ) :
				( new TestUser( $username ) )->getUser();

			return [ $title, $user, $type, $task ];
		}, $todo );

		$tasks = [];
		foreach ( $todo as $testParameters ) {
			list( $title, $user, $type, $task ) = $testParameters;
			if ( $task ) {
				// Remember this task for use in assertSelect() checks below.
				$tasks[$this->taskKey( $title, $user, $type )] = $task;

				// Create and run the Consequence.
				$consequence = new InstallApproveHookConsequence( $title, $user, $type, $task );
				$consequence->run();
			}
		}

		// Track ChangeTagsAfterUpdateTags hook to ensure that $task['tags'] are actually added.
		$taggedRevIds = [];
		$taggedLogIds = [];
		$taggedRcIds = [];
		$this->setTemporaryHook( 'ChangeTagsAfterUpdateTags', function (
			$tagsToAdd, $tagsToRemove, $prevTags,
			$rc_id, $rev_id, $log_id, $params, $rc, $user
		) use ( $tasks, &$taggedRevIds, &$taggedLogIds, &$taggedRcIds ) {
			$task = $tasks[$this->taskKey(
				$rc->getTitle(),
				$rc->getPerformer(),
				'edit' # TODO: support checking moves too
			)];

			$this->assertEquals( explode( "\n", $task['tags'] ), $tagsToAdd );
			$this->assertEquals( [], $tagsToRemove );

			if ( $rev_id !== false ) {
				$taggedRevIds[] = $rev_id;
			}

			if ( $log_id !== false ) {
				$taggedLogIds[] = $log_id;
			}

			if ( $rc_id !== false ) {
				$taggedRcIds[] = $rc_id;
			}

			return true;
		} );

		// Now make new edits and double-check that all changes from $task were applied to them.
		$this->setMwGlobals( 'wgModerationEnable', false ); // Edits shouldn't be intercepted

		// FIXME: Temporarily commented, because timestamps in testInstallApproveHook() are wrong
		// for this test (with 3 edits per page, only one row would have rev_timestamp changed).
		//$this->setMwGlobals( 'wgCommandLineMode', false ); // Delay any DeferredUpdates

		$revIds = [];
		foreach ( $todo as $testParameters ) {
			list( $title, $user ) = $testParameters;
			$revIds[] = $this->makeEdit( $title, $user );
		}

		// Run any DeferredUpdates that may have been queued when making edits.
		// Note: PRESEND must be first, as this is where RecentChanges_save hooks are called,
		// and results of these hooks are used by ApproveHook, which is in POSTSEND.
		DeferredUpdates::doUpdates( 'run', DeferredUpdates::PRESEND );
		DeferredUpdates::doUpdates( 'run', DeferredUpdates::POSTSEND );

		// TODO: test moves: both redirect revision and "page moves" null revision should be affected.
		// TODO: check that correct User+Title pairs got correct results.

		$this->assertSelect( 'revision',
			[ 'rev_timestamp' ],
			[ 'rev_id' => $revIds ],
			array_map( function ( $task ) {
				return [ $this->db->timestamp( $task['timestamp'] ) ];
			}, $tasks ),
			[ 'ORDER BY' => 'rev_id' ]
		);

		$this->assertSelect( 'recentchanges',
			[ 'rc_ip' ],
			[ 'rc_this_oldid' => $revIds ],
			array_map( function ( $task ) {
				$expectedIP = $task['ip'];
				if ( $this->db->getType() == 'postgres' ) {
					$expectedIP .= '/32';
				}
				return [ $expectedIP ];
			}, $tasks ),
			[ 'ORDER BY' => 'rc_id' ]
		);

		// FIXME: if the method from Extension:CheckUser gets renamed in some future version,
		// this part of test will be silently skipped, and we won't notice it.
		// To avoid this, this check should be in a separate test method,
		// which would be using proper @requires or markTestSkipped().
		if ( method_exists( 'CheckUserHooks', 'updateCheckUserData' ) ) {
			$this->assertSelect( 'cu_changes',
				[
					'cuc_ip',
					'cuc_ip_hex',
					'cuc_agent'
				],
				[ 'cuc_this_oldid' => $revIds ],
				array_map( function ( $task ) {
					return [
						$task['ip'],
						IP::toHex( $task['ip'] ),
						$task['ua']
					];
				}, $tasks ),
				[ 'ORDER BY' => 'cuc_id' ]
			);
		}

		// Check that ChangeTagsAfterUpdateTags hook was called for all revisions, etc.
		// Note: ChangeTagsAfterUpdateTags hook (see above) checks "were added tags valid or not".
		$this->assertEquals( $revIds, $taggedRevIds );
		$this->assertSelect( 'recentchanges',
			[ 'rc_id', 'rc_this_oldid' ],
			[ 'rc_this_oldid' => $revIds ],
			array_map( function ( $rc_id, $rev_id ) {
				return [
					$rc_id,
					$rev_id,
				];
			}, $taggedRcIds, $revIds )
		);
		$this->assertSelect( 'logging',
			[ 'log_id' ],
			'',
			array_map( function ( $log_id ) {
				return [ $log_id ];
			}, $taggedLogIds )
		);
	}

	/**
	 * Make one test edit on behalf of $user in page $title.
	 * @param Title $title
	 * @param User $user
	 * @return int rev_id of the newly created edit.
	 */
	private function makeEdit( Title $title, User $user ) {
		$text = 'Some text ' . rand( 0, 100000 ) . ' in page ' .
			$title->getFullText() . ' by ' . $user->getName();

		$page = WikiPage::factory( $title );
		$status = $page->doEditContent(
			ContentHandler::makeContent( $text, null, CONTENT_MODEL_WIKITEXT ),
			'Some edit summary',
			EDIT_INTERNAL,
			false,
			$user
		);
		$this->assertTrue( $status->isGood(), "Edit failed: " . $status->getMessage()->plain() );

		return $status->value['revision']->getId();
	}

	/**
	 * Returns key for $tasks array.
	 * Note: this is test-specific, it is NOT the same as ModerationApproveHook::getTaskKey().
	 * @param Title $title
	 * @param User $user
	 * @param string $type
	 * @return string
	 */
	private function taskKey( Title $title, User $user, $type ) {
		return $title->getPrefixedDBKey() . '|' . $user->getName() . '|' . $type;
	}

	/**
	 * Destroy ApproveHook object after the test.
	 */
	protected function tearDown() {
		ModerationApproveHook::destroySingleton();
		parent::tearDown();
	}

	public function setUp() {
		parent::setUp();

		// Workaround for MediaWiki 1.31 only: its TestCase class doesn't clean tables properly
		global $wgVersion;
		if ( version_compare( $wgVersion, '1.32.0', '<' ) && $this->db->getType() == 'mysql' ) {
			foreach ( $this->tablesUsed as $table ) {
				$this->db->delete( $this->db->tableName( $table ), '*', __METHOD__ );
			}
		}
	}

	/**
	 * Override the parent method, so that UTSysop and UTPage are not created.
	 * This test doesn't use them, and having to filter them out complicates assertSelect() calls.
	 */
	protected function addCoreDBData() {
		// Nothing
	}
}
