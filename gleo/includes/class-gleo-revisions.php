<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Tracks every Gleo edit so the user can roll back.
 * For posts: relies on WP's native revisions + a 'gleo_changelog' option that records what we touched.
 * For site-wide changes (robots.txt, llms.txt, options): stores prior values keyed by change_id.
 */
class Gleo_Revisions {
	const OPTION = 'gleo_changelog';
	const MAX_ENTRIES = 200;

	public static function record( $args ) {
		$entry = wp_parse_args( $args, [
			'id'        => wp_generate_uuid4(),
			'time'      => current_time( 'mysql' ),
			'type'      => 'unknown',
			'post_id'   => 0,
			'fix_type'  => '',
			'summary'   => '',
			'before'    => null,
			'after'     => null,
			'reverted'  => false,
		] );

		$log = get_option( self::OPTION, [] );
		array_unshift( $log, $entry );
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, 0, self::MAX_ENTRIES );
		}
		update_option( self::OPTION, $log, false );
		return $entry['id'];
	}

	public static function all() {
		return get_option( self::OPTION, [] );
	}

	public static function find( $id ) {
		foreach ( self::all() as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}
		return null;
	}

	public static function mark_reverted( $id ) {
		$log = self::all();
		foreach ( $log as &$entry ) {
			if ( $entry['id'] === $id ) {
				$entry['reverted'] = true;
				break;
			}
		}
		update_option( self::OPTION, $log, false );
	}

	public static function revert( $id ) {
		$entry = self::find( $id );
		if ( ! $entry ) {
			return new WP_Error( 'gleo_not_found', 'Change not found.' );
		}
		if ( $entry['reverted'] ) {
			return new WP_Error( 'gleo_already_reverted', 'Already reverted.' );
		}

		switch ( $entry['type'] ) {
			case 'post_content':
				if ( ! $entry['post_id'] || $entry['before'] === null ) {
					return new WP_Error( 'gleo_bad_entry', 'Missing post data for revert.' );
				}
				$result = wp_update_post( [
					'ID'           => $entry['post_id'],
					'post_content' => $entry['before'],
				], true );
				if ( is_wp_error( $result ) ) { return $result; }
				break;

			case 'post_meta':
				if ( ! $entry['post_id'] || empty( $entry['after']['key'] ) ) {
					return new WP_Error( 'gleo_bad_entry', 'Missing meta data for revert.' );
				}
				if ( $entry['before'] === null || $entry['before'] === '' ) {
					delete_post_meta( $entry['post_id'], $entry['after']['key'] );
				} else {
					update_post_meta( $entry['post_id'], $entry['after']['key'], $entry['before'] );
				}
				break;

			case 'option':
				if ( empty( $entry['after']['key'] ) ) {
					return new WP_Error( 'gleo_bad_entry', 'Missing option key for revert.' );
				}
				if ( $entry['before'] === null ) {
					delete_option( $entry['after']['key'] );
				} else {
					update_option( $entry['after']['key'], $entry['before'], false );
				}
				break;

			default:
				return new WP_Error( 'gleo_unsupported', 'This change type cannot be auto-reverted.' );
		}

		self::mark_reverted( $id );
		return true;
	}

	public static function revert_all() {
		$log = self::all();
		$reverted = 0;
		$errors   = [];
		foreach ( $log as $entry ) {
			if ( $entry['reverted'] ) { continue; }
			$r = self::revert( $entry['id'] );
			if ( is_wp_error( $r ) ) {
				$errors[] = $entry['id'] . ': ' . $r->get_error_message();
			} else {
				$reverted++;
			}
		}
		return [ 'reverted' => $reverted, 'errors' => $errors ];
	}
}
