<?php
/**
 * Hooked functions used by the Comments extension.
 * All class methods are public and static.
 *
 * @file
 * @ingroup Extensions
 * @author Jack Phoenix
 * @author Alexia E. Smith
 * @copyright (c) 2013 Curse Inc.
 * @license GPL-2.0-or-later
 * @link https://www.mediawiki.org/wiki/Extension:Comments Documentation
 */

class CommentsHooks {
	/**
	 * Registers the following tags and magic words:
	 * - <comments />
	 * - NUMBEROFCOMMENTSPAGE
	 *
	 * @param Parser &$parser
	 */
	public static function onParserFirstCallInit( Parser &$parser ) {
		$parser->setHook( 'comments', [ 'DisplayComments', 'getParserHandler' ] );
		$parser->setFunctionHook( 'NUMBEROFCOMMENTSPAGE', 'NumberOfComments::getParserHandler', Parser::SFH_NO_HASH );
	}

	/**
	 * For the Echo extension: register our new presentation model with Echo so
	 * Echo knows how it should display our notifications in it.
	 *
	 * @param array &$notifications Echo notifications
	 * @param array &$notificationCategories Echo notification categories
	 * @param array &$icons Icon details
	 */
	public static function onBeforeCreateEchoEvent( &$notifications, &$notificationCategories, &$icons ) {
		$notificationCategories['mention-comment'] = [
			'priority' => 4,
			'tooltip' => 'echo-pref-tooltip-mention-comment',
		];

		$notifications['mention-comment'] = [
			'category' => 'mention-comment',
			'group' => 'interactive',
			'section' => 'alert',
			'presentation-model' => 'EchoMentionCommentPresentationModel',
            'user-locators' => [ [ 'EchoUserLocator::locateFromEventExtra', [ 'mentioned-users' ] ] ]
        ];

        $icons['mention-comment']['path'] = 'Echo/modules/icons/mention-progressive.svg';

		$notificationCategories['reply-comment'] = [
			'priority' => 4,
			'tooltip' => 'echo-pref-tooltip-reply-comment',
		];

		$notifications['reply-comment'] = [
			'category' => 'reply-comment',
			'group' => 'interactive',
			'section' => 'alert',
			'presentation-model' => 'EchoReplyCommentPresentationModel',
			'user-locators' => [ [ 'EchoUserLocator::locateFromEventExtra', [ 'thread-users' ] ] ],
            'user-filters' => [ [ 'EchoUserLocator::locateFromEventExtra', [ 'mentioned-users' ] ] ]
		];

        $icons['reply-comment']['path'] = 'Echo/modules/icons/edit-user-talk-progressive.svg';
        
        $notificationCategories['watched-comment'] = [
			'priority' => 4,
			'tooltip' => 'echo-pref-tooltip-watched-comment',
		];

		$notifications['watched-comment'] = [
			'category' => 'watched-comment',
			'group' => 'neutral',
			'section' => 'message',
			'presentation-model' => 'EchoWatchedCommentPresentationModel',
			'user-locators' => [ 'EchoUserLocator::locateUsersWatchingTitle' ],
            'user-filters' => [ [ 'EchoUserLocator::locateFromEventExtra', [ 'mentioned-users' ] ],
                                [ 'EchoUserLocator::locateFromEventExtra', [ 'thread-users' ] ] ]
		];

        $icons['watched-comment']['path'] = 'Echo/modules/icons/speechBubbles-ltr-progressive.svg';
	}

    public static function onBeforePageDisplay( OutputPage $out, Skin $skin ) {
        $namespace = $out->getTitle()->getNamespace();
        if ( $namespace == NS_MAIN || $namespace == NS_BLOG ) {
            $out->addWikiTextAsContent('<comments/>');
        }
    }

	/**
	 * Adds the three new required database tables into the database when the
	 * user runs /maintenance/update.php (the core database updater script).
	 *
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = __DIR__ . '/../sql';

		$db = $updater->getDB();
		$dbType = $db->getType();

		// For non-MySQL/MariaDB/SQLite DBMSes, use the appropriately named files
		$patchFileSuffix = '';
		if ( !in_array( $dbType, [ 'mysql', 'sqlite' ] ) ) {
			$comments = "comments.{$dbType}.sql";
			$comments_vote = "comments_vote.{$dbType}.sql";
			$comments_block = "comments_block.{$dbType}.sql";
			$patchFileSuffix = '.' . $dbType; // e.g. ".postgres"
		} else {
			$comments = 'comments.sql';
			$comments_vote = 'comments_vote.sql';
			$comments_block = 'comments_block.sql';
		}

		$updater->addExtensionTable( 'Comments', "{$dir}/{$comments}" );
		$updater->addExtensionTable( 'Comments_Vote', "{$dir}/{$comments_vote}" );
		$updater->addExtensionTable( 'Comments_block', "{$dir}/{$comments_block}" );

		// Actor support
		if ( !$db->fieldExists( 'Comments', 'Comment_actor', __METHOD__ ) ) {
			// 1) add new actor columns
			$updater->addExtensionField( 'Comments', 'Comment_actor', "$dir/patches/actor/add-Comment_actor{$patchFileSuffix}.sql" );
			// 2) add the corresponding indexes
			$updater->addExtensionIndex( 'Comments', 'wiki_actor', "$dir/patches/actor/add-wiki_actor_index.sql" );
			// 3) populate the new column with data
			$updater->addExtensionUpdate( [
				'runMaintenance',
				'MigrateOldCommentsUserColumnsToActor',
				"$dir/../maintenance/migrateOldCommentsUserColumnsToActor.php"
			] );
			// 4) drop old columns & indexes
			$updater->dropExtensionField( 'Comments', 'Comment_user_id', "$dir/patches/actor/drop-Comment_user_id.sql" );
			$updater->dropExtensionField( 'Comments', 'Comment_Username', "$dir/patches/actor/drop-Comment_Username.sql" );
			$updater->dropExtensionIndex( 'Comments', 'wiki_user_id', "$dir/patches/actor/drop-wiki_user_id-index.sql" );
			$updater->dropExtensionIndex( 'Comments', 'wiki_user_name', "$dir/patches/actor/drop-wiki_user_name-index.sql" );
		}

		if ( !$db->fieldExists( 'Comments_block', 'cb_actor', __METHOD__ ) ) {
			// 1) add new actor columns
			$updater->addExtensionField( 'Comments_block', 'cb_actor', "$dir/patches/actor/add-cb_actor{$patchFileSuffix}.sql" );
			$updater->addExtensionField( 'Comments_block', 'cb_actor_blocked', "$dir/patches/actor/add-cb_actor_blocked{$patchFileSuffix}.sql" );
			// 2) add the corresponding indexes
			$updater->addExtensionIndex( 'Comments_block', 'cb_actor', "$dir/patches/actor/add-cb_actor-index.sql" );
			// 3) populate the new column with data
			$updater->addExtensionUpdate( [
				'runMaintenance',
				'MigrateOldCommentsBlockUserColumnsToActor',
				"$dir/../maintenance/migrateOldCommentsBlockUserColumnsToActor.php"
			] );
			// 4) drop old columns & indexes
			$updater->dropExtensionField( 'Comments_block', 'cb_user_id', "$dir/patches/actor/drop-cb_user_id.sql" );
			$updater->dropExtensionField( 'Comments_block', 'cb_user_name', "$dir/patches/actor/drop-cb_user_name.sql" );
			$updater->dropExtensionField( 'Comments_block', 'cb_user_id_blocked', "$dir/patches/actor/drop-cb_user_id_blocked.sql" );
			$updater->dropExtensionField( 'Comments_block', 'cb_user_name_blocked', "$dir/patches/actor/drop-cb_user_name_blocked.sql" );
			$updater->dropExtensionIndex( 'Comments_block', 'cb_user_id', "$dir/patches/actor/drop-cb_user_id-index.sql" );
		}

		if ( !$db->fieldExists( 'Comments_Vote', 'Comment_Vote_actor', __METHOD__ ) ) {
			// 1) add new actor columns
			$updater->addExtensionField( 'Comments_Vote', 'Comment_Vote_actor', "$dir/patches/actor/add-Comment_Vote_actor{$patchFileSuffix}.sql" );
			// 2) add the corresponding indexes
			$updater->addExtensionIndex( 'Comments_Vote', 'Comments_Vote_actor_index', "$dir/patches/actor/add-Comment_Vote_unique_actor_index.sql" );
			$updater->addExtensionIndex( 'Comments_Vote', 'Comment_Vote_actor', "$dir/patches/actor/add-Comment_Vote_actor-index.sql" );
			// 3) populate the new column with data
			$updater->addExtensionUpdate( [
				'runMaintenance',
				'MigrateOldCommentsVoteUserColumnsToActor',
				"$dir/../maintenance/migrateOldCommentsVoteUserColumnsToActor.php"
			] );
			// 4) drop old columns & indexes
			$updater->dropExtensionField( 'Comments_Vote', 'Comment_Vote_user_id', "$dir/patches/actor/drop-Comment_Vote_user_id.sql" );
			$updater->dropExtensionIndex( 'Comments_Vote', 'Comments_Vote_user_id_index', "$dir/patches/actor/drop-Comments_Vote_user_id_index.sql" );
			$updater->dropExtensionField( 'Comments_Vote', 'Comment_Vote_Username', "$dir/patches/actor/drop-Comment_Vote_Username.sql" );
			$updater->dropExtensionIndex( 'Comments_Vote', 'Comment_Vote_user_id', "$dir/patches/actor/drop-Comment_Vote_user_id-index.sql" );
		}
	}
}
