<?php

class CommentDeleteAPI extends ApiBase {

	public function execute() {
		$user = $this->getUser();

		$comment = Comment::newFromID( $this->getMain()->getVal( 'commentID' ) );
		// Blocked users cannot delete comments, and neither can unprivileged ones.
		if (
			$user->isBlocked() ||
			!(
				$user->isAllowed( 'commentadmin' ) ||
				$user->isAllowed( 'comment-delete-own' ) && $comment->isOwner( $user )
			)
		) {
			return true;
		}

		$comment->delete();

        $pageID = $comment->page->id;
        $title = Title::newFromId( $pageID );
        $job = new HTMLCacheUpdateJob( $title, [] );
        JobQueueGroup::singleton()->lazyPush( $job );

		$result = $this->getResult();
		$result->addValue( $this->getModuleName(), 'ok', 'ok' );
		return true;
	}

	public function needsToken() {
		return 'csrf';
	}

	public function isWriteMode() {
		return true;
	}

	public function getAllowedParams() {
		return [
			'commentID' => [
				ApiBase::PARAM_REQUIRED => true,
				ApiBase::PARAM_TYPE => 'integer'
			]
		];
	}
}
