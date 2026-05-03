<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Computes a 0-100 GEO score from a scan report.
 * Weights are by category. Severity penalties stack but cap per category.
 */
class Gleo_Scorer {

	private static $weights = [
		'content'    => 18,
		'substance'  => 18,
		'structure'  => 22,
		'technical'  => 28,
		'trust'      => 14,
	];

	private static $severity_penalty = [
		'low'    => 3,
		'medium' => 8,
		'high'   => 15,
	];

	public static function score( $report ) {
		$by_cat = [];
		foreach ( self::$weights as $cat => $w ) {
			$by_cat[ $cat ] = $w;
		}

		foreach ( $report['issues'] as $issue ) {
			$cat = isset( $issue['category'] ) ? $issue['category'] : 'content';
			$pen = self::$severity_penalty[ $issue['severity'] ] ?? 5;
			if ( ! isset( $by_cat[ $cat ] ) ) { continue; }
			$by_cat[ $cat ] = max( 0, $by_cat[ $cat ] - $pen );
		}

		$score = 0;
		foreach ( $by_cat as $cat => $remaining ) {
			$score += $remaining;
		}

		$total_weight = array_sum( self::$weights );
		$normalized   = (int) round( ( $score / $total_weight ) * 100 );
		$normalized   = max( 0, min( 100, $normalized ) );

		$band = 'critical';
		if ( $normalized >= 85 )       { $band = 'excellent'; }
		elseif ( $normalized >= 70 )   { $band = 'good'; }
		elseif ( $normalized >= 50 )   { $band = 'fair'; }
		elseif ( $normalized >= 30 )   { $band = 'poor'; }

		return [
			'score'        => $normalized,
			'band'         => $band,
			'by_category'  => self::category_breakdown( $by_cat ),
			'issue_counts' => self::issue_counts( $report['issues'] ),
		];
	}

	private static function category_breakdown( $by_cat ) {
		$out = [];
		foreach ( self::$weights as $cat => $w ) {
			$remaining = $by_cat[ $cat ];
			$out[ $cat ] = [
				'percent' => $w > 0 ? (int) round( ( $remaining / $w ) * 100 ) : 0,
				'weight'  => $w,
			];
		}
		return $out;
	}

	private static function issue_counts( $issues ) {
		$counts = [ 'high' => 0, 'medium' => 0, 'low' => 0, 'auto_fixable' => 0, 'total' => 0 ];
		foreach ( $issues as $issue ) {
			$counts['total']++;
			if ( isset( $counts[ $issue['severity'] ] ) ) {
				$counts[ $issue['severity'] ]++;
			}
			if ( ! empty( $issue['auto_fixable'] ) ) {
				$counts['auto_fixable']++;
			}
		}
		return $counts;
	}
}
