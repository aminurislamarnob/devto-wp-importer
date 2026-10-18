<?php

namespace WeLabs\DevtoWpImporter\Engagement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend comments, stored as native WordPress comments.
 *
 * Threads are one level deep: a reply to a reply is filed under the comment
 * that started the thread. New comments go through wp_new_comment(), so the
 * site's Discussion settings (hold every comment, or only first-time
 * commenters), flood and duplicate checks, notifications and spam plugins
 * all apply as they would to a theme's comment form.
 *
 * Frontend comments are Markdown, rendered by the frontend. They're stored
 * with &, < and > as entities so kses can't strip a code sample's `<div>`,
 * and flagged with MARKDOWN_META so the API hands back the exact Markdown.
 * Comments written in wp-admin are HTML and come back as plain text.
 */
class Comments {

	const MAX_AUTHOR_LENGTH  = 80;
	const MAX_CONTENT_LENGTH = 5000;
	const MARKDOWN_META      = '_devto_wp_importer_markdown';

	/**
	 * Approved comments on a post, oldest first, each with its replies.
	 *
	 * @param \WP_Post $post Post.
	 *
	 * @return array[]
	 */
	public function for_post( $post ) {
		$comments = get_comments(
			[
				'post_id' => $post->ID,
				'status'  => 'approve',
				'type'    => 'comment',
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
			]
		);

		$by_id = [];
		foreach ( $comments as $comment ) {
			$by_id[ (int) $comment->comment_ID ] = $comment;
		}

		$threads = [];
		foreach ( $comments as $comment ) {
			$root_id = $this->thread_root( $comment, $by_id );

			if ( $root_id === (int) $comment->comment_ID ) {
				$threads[ $root_id ] = $this->format( $comment, $post ) + [ 'replies' => [] ];
			}
		}

		foreach ( $comments as $comment ) {
			$root_id = $this->thread_root( $comment, $by_id );

			if ( $root_id !== (int) $comment->comment_ID && isset( $threads[ $root_id ] ) ) {
				$threads[ $root_id ]['replies'][] = $this->format( $comment, $post, $root_id );
			}
		}

		return array_values( $threads );
	}

	/**
	 * Validate and save a comment from the frontend form.
	 *
	 * @param \WP_Post $post   Post being commented on.
	 * @param array    $fields { author, email, content, parent }.
	 * @param string   $ip     Commenter IP address.
	 * @param string   $agent  Commenter user agent.
	 *
	 * @return array|\WP_Error { status: 'approved'|'pending', comment: array|null }
	 */
	public function create( $post, array $fields, $ip, $agent ) {
		if ( ! comments_open( $post->ID ) ) {
			return new \WP_Error( 'devto_wp_importer_comments_closed', __( 'Comments are closed on this post.', 'devto-wp-importer' ), [ 'status' => 403 ] );
		}

		$author  = trim( sanitize_text_field( $fields['author'] ?? '' ) );
		$email   = sanitize_email( $fields['email'] ?? '' );
		$content = self::clean_markdown( $fields['content'] ?? '' );
		$parent  = absint( $fields['parent'] ?? 0 );

		$errors = [];

		if ( '' === $author ) {
			$errors['author'] = __( 'Please enter your name.', 'devto-wp-importer' );
		} elseif ( mb_strlen( $author ) > self::MAX_AUTHOR_LENGTH ) {
			$errors['author'] = __( 'Please use a shorter name.', 'devto-wp-importer' );
		}

		if ( ! is_email( $email ) ) {
			$errors['email'] = __( 'Please enter a valid email address.', 'devto-wp-importer' );
		}

		if ( '' === $content ) {
			$errors['content'] = __( 'Please write a comment.', 'devto-wp-importer' );
		} elseif ( mb_strlen( $content ) > self::MAX_CONTENT_LENGTH ) {
			/* translators: %d: maximum number of characters */
			$errors['content'] = sprintf( __( 'Please keep your comment under %d characters.', 'devto-wp-importer' ), self::MAX_CONTENT_LENGTH );
		}

		if ( $parent ) {
			$parent = $this->reply_target( $parent, $post->ID );

			if ( ! $parent ) {
				$errors['parent'] = __( 'The comment you are replying to is no longer available.', 'devto-wp-importer' );
			}
		}

		if ( $errors ) {
			return new \WP_Error(
				'devto_wp_importer_invalid_comment',
				__( 'Please check the highlighted fields.', 'devto-wp-importer' ),
				[
					'status' => 400,
					'fields' => $errors,
				]
			);
		}

		$comment_id = wp_new_comment(
			[
				'comment_post_ID'      => $post->ID,
				'comment_author'       => $author,
				'comment_author_email' => $email,
				'comment_author_url'   => '',
				'comment_content'      => self::encode_markdown( $content ),
				'comment_parent'       => $parent,
				'comment_type'         => 'comment',
				'user_id'              => 0,
				'comment_author_IP'    => $ip,
				'comment_agent'        => $agent,
			],
			true
		);

		if ( is_wp_error( $comment_id ) ) {
			// WordPress gives duplicate (409) and flood (429) errors their HTTP
			// status as plain integer data; make it the REST status.
			$data   = $comment_id->get_error_data();
			$status = is_array( $data ) && ! empty( $data['status'] ) ? $data['status'] : ( is_int( $data ) ? $data : 400 );
			$comment_id->add_data( [ 'status' => $status ] );

			return $comment_id;
		}

		add_comment_meta( $comment_id, self::MARKDOWN_META, 1, true );

		$comment = get_comment( $comment_id );

		// Anything not approved (held, or caught as spam) reads as "awaiting
		// moderation": a spammer learns nothing from the answer.
		if ( ! $comment || 'approved' !== wp_get_comment_status( $comment ) ) {
			return [
				'status'  => 'pending',
				'comment' => null,
			];
		}

		return [
			'status'  => 'approved',
			'comment' => $this->format( $comment, $post, $parent ) + ( $parent ? [] : [ 'replies' => [] ] ),
		];
	}

	/**
	 * Markdown as typed, made safe to store: valid UTF-8, \n line endings, no
	 * control characters, trimmed. Unlike sanitize_textarea_field() it keeps
	 * angle brackets and %xx sequences, which code samples and URLs need.
	 *
	 * @param string $text Submitted comment.
	 *
	 * @return string
	 */
	public static function clean_markdown( $text ) {
		$text = wp_check_invalid_utf8( (string) $text );
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		// Control characters other than tab and newline. Not all of \p{C}:
		// zero-width joiners hold emoji and Bangla conjuncts together.
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

		return trim( $text );
	}

	/**
	 * Markdown as stored in comment_content: &, < and > as entities, so kses
	 * leaves them alone.
	 *
	 * @param string $markdown Cleaned Markdown.
	 *
	 * @return string
	 */
	public static function encode_markdown( $markdown ) {
		return htmlspecialchars( $markdown, ENT_NOQUOTES, 'UTF-8' );
	}

	/**
	 * The Markdown a stored comment was written as.
	 *
	 * @param string $stored comment_content.
	 *
	 * @return string
	 */
	public static function decode_markdown( $stored ) {
		return htmlspecialchars_decode( $stored, ENT_NOQUOTES );
	}

	/**
	 * The comment a reply attaches to: the parent itself, or the comment that
	 * started its thread when the parent is a reply. 0 if it isn't an approved
	 * comment on this post.
	 */
	private function reply_target( $parent_id, $post_id ) {
		$parent = get_comment( $parent_id );

		if ( ! $parent || (int) $parent->comment_post_ID !== (int) $post_id || '1' !== (string) $parent->comment_approved ) {
			return 0;
		}

		$grandparent = (int) $parent->comment_parent;

		return $grandparent ? $this->reply_target( $grandparent, $post_id ) : (int) $parent->comment_ID;
	}

	/**
	 * The top-level comment a comment's thread hangs from. Replies whose
	 * parent isn't approved start their own thread.
	 */
	private function thread_root( $comment, array $by_id ) {
		$id   = (int) $comment->comment_ID;
		$seen = [];

		while ( (int) $by_id[ $id ]->comment_parent && isset( $by_id[ (int) $by_id[ $id ]->comment_parent ] ) && ! isset( $seen[ $id ] ) ) {
			$seen[ $id ] = true;
			$id          = (int) $by_id[ $id ]->comment_parent;
		}

		return $id;
	}

	private function format( $comment, $post, $parent_id = 0 ) {
		$user_id = (int) $comment->user_id;

		return [
			'id'        => (int) $comment->comment_ID,
			'parent'    => (int) $parent_id,
			'author'    => wp_strip_all_tags( $comment->comment_author ),
			// Written by the post's author (signed in to WordPress), e.g. a
			// reply from the admin's Comments screen.
			'is_author' => $user_id > 0 && $user_id === (int) $post->post_author,
			'date'      => gmdate( 'c', strtotime( $comment->comment_date_gmt . ' UTC' ) ),
			'content'   => $this->content( $comment ),
		];
	}

	/**
	 * A comment's text as Markdown for the frontend: as written for frontend
	 * comments, and the text of HTML ones written in wp-admin.
	 */
	private function content( $comment ) {
		if ( get_comment_meta( $comment->comment_ID, self::MARKDOWN_META, true ) ) {
			return self::decode_markdown( $comment->comment_content );
		}

		return html_entity_decode( wp_strip_all_tags( $comment->comment_content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
