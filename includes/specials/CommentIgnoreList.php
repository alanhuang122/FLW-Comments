<?php
/**
 * A special page for displaying the list of users whose comments you're
 * ignoring.
 * @file
 * @ingroup Extensions
 */
class CommentIgnoreList extends SpecialPage {

	/**
	 * Constructor -- set up the new special page
	 */
	public function __construct() {
		parent::__construct( 'CommentIgnoreList' );
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * Group this special page under the correct header in Special:SpecialPages.
	 *
	 * @return string
	 */
	function getGroupName() {
		return 'users';
	}

	/**
	 * Show this special page on Special:SpecialPages only for registered users
	 *
	 * @return bool
	 */
	function isListed() {
		return (bool)$this->getUser()->isLoggedIn();
	}

	/**
	 * Show the special page
	 *
	 * @param mixed|null $par Parameter passed to the page or null
	 */
	public function execute( $par ) {
		$out = $this->getOutput();
		$request = $this->getRequest();
		$user = $this->getUser();

        $actor_id = $request->getInt( 'actor' );

		/**
		 * Redirect anonymous users to Login Page
		 * It will automatically return them to the CommentIgnoreList page
		 */
		if ( $user->getId() == 0 && $actor_id == 0 ) {
			$loginPage = SpecialPage::getTitleFor( 'Userlogin' );
			$out->redirect( $loginPage->getLocalURL( 'returnto=Special:CommentIgnoreList' ) );
			return;
		}

		$out->setPageTitle( $this->msg( 'comments-ignore-title' )->text() );

		$output = ''; // Prevent E_NOTICE

		if ( $actor_id == 0 ) {
			$output .= $this->displayCommentBlockList();
		} else {
			if ( $request->wasPosted() ) {
				// Check for cross-site request forgeries (CSRF)
				if ( !$user->matchEditToken( $request->getVal( 'token' ) ) ) {
					$out->addWikiMsg( 'sessionfailure' );
					return;
				}

				$blockedUser = User::newFromActorId( $actor_id );
                $user_id = $blockedUser->getId();

				if ( !$user_id ) {
					$user_id = 0;
				}

				if ( $blockedUser instanceof User ) {
					CommentFunctions::deleteBlock( $user, $blockedUser );
				}

				// Update social statistics
				if ( $user_id && class_exists( 'UserStatsTrack' ) ) {
					$stats = new UserStatsTrack( $user_id, $user_name );
					$stats->decStatField( 'comment_ignored' );
				}

				$output .= $this->displayCommentBlockList();
			} else {
				$output .= $this->confirmCommentBlockDelete();
			}
		}

		$out->addHTML( $output );
	}

	/**
	 * Displays the list of users whose comments you're ignoring.
	 *
	 * @return string HTML
	 */
	private function displayCommentBlockList() {
		$lang = $this->getLanguage();
		$title = $this->getPageTitle();

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			[ 'Comments_block', 'actor' ],
			[ 'cb_actor_blocked', 'cb_date' ],
			[ 'cb_actor' => $this->getUser()->getActorId() ],
			__METHOD__,
			[ 'ORDER BY' => 'actor_name' ],
			[ 'actor' => [ 'JOIN', 'actor_id = cb_actor' ] ]
		);

		if ( $dbr->numRows( $res ) > 0 ) {
			$out = '<ul>';
			foreach ( $res as $row ) {
				$user = User::newFromActorId( $row->cb_actor_blocked );
				if ( !$user ) {
					continue;
				}
				$user_title = $user->getUserPage();
				$out .= '<li>' . $this->msg(
					'comments-ignore-item',
					htmlspecialchars( $user_title->getFullURL() ),
					$user_title->getText(),
					$lang->timeanddate( $row->cb_date ),
					htmlspecialchars( $title->getFullURL( 'actor=' . $row->cb_actor_blocked ) )
				)->text() . '</li>';
			}
			$out .= '</ul>';
		} else {
			$out = '<div class="comment_blocked_user">' .
				$this->msg( 'comments-ignore-no-users' )->text() . '</div>';
		}
		return $out;
	}

	/**
	 * Asks for a confirmation when you're about to unblock someone's comments.
	 *
	 * @return string HTML
	 */
	private function confirmCommentBlockDelete() {
        $actor_id = $this->getRequest()->getVal( 'actor' );
        $user = User::newFromActorId( $actor_id );
        $user_name = $user->getName();

		$out = '<div class="comment_blocked_user">' .
				$this->msg( 'comments-ignore-remove-message', $user_name )->parse() .
			'</div>
			<div>
				<form action="" method="post" name="comment_block">' .
					Html::hidden( 'user', $user_name ) . "\n" .
					Html::hidden( 'token', $this->getUser()->getEditToken() ) . "\n" .
					'<input type="button" class="site-button" value="' . $this->msg( 'comments-ignore-unblock' )->text() . '" onclick="document.comment_block.submit()" />
					<input type="button" class="site-button" value="' . $this->msg( 'comments-ignore-cancel' )->text() . '" onclick="history.go(-1)" />
				</form>
			</div>';
		return $out;
	}
}
