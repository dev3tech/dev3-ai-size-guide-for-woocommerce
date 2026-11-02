<?php
/**
 * Keyword classifier for return reasons (no AI): too_small, too_large, fit_ok, other, unknown.
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Learning;

defined( 'ABSPATH' ) || exit;

/**
 * Classifier.
 */
class Classifier {

	const REASONS = array( 'too_small', 'too_large', 'fit_ok', 'other', 'unknown' );

	/**
	 * Patterns per reason (EN, DE, FR, ES, NL). Filterable.
	 *
	 * @return array
	 */
	public static function patterns() {
		$p = array(
			'too_small'  => '/\b(too (small|tight|short|narrow|snug)|runs? small|size up|sizing up|(a |one )?(bigger|larger) (size|one)|one size up|zu (klein|eng|kurz)|trop (petit|serr[ée]|court)|demasiado (peque[ñn]o|apretado|corto)|te (klein|krap|kort))/iu',
			'too_large'  => '/\b(too (big|large|loose|long|wide|baggy)|runs? (large|big)|size down|sizing down|(a |one )?smaller (size|one)|one size down|zu (gro(ß|ss)|weit|lang)|trop (grand|large|long)|demasiado (grande|holgado|largo)|te (groot|ruim|lang))/iu',
			'weak_small' => '/\b(tight|small|snug|kleiner?|eng|petit|serr[ée]|peque[ñn]o|apretado|klein|krap)\b/iu',
			'weak_large' => '/\b(loose|baggy|roomy|big|large|huge|gro(ß|ss)|weit|grand|grande|holgado|groot|ruim)\b/iu',
			'fit_ok'     => '/\b(fits? (well|great|perfect(ly)?|fine)|perfect fit|right size|true to size|size (was|is) (fine|ok|correct)|passt gut|taille parfaite|talla correcta|past goed)/iu',
			'other'      => '/\b(damaged|defect(ive)?|broken|stain|hole|colou?r|quality|late|delay|changed (my )?mind|no longer|wrong (item|product|colou?r)|not as (described|pictured)|ordered (two|2|multiple)|duplicate|gift|besch(ä|a)digt|farbe|qualit(ä|a)t|endommag|couleur|qualit(é|e)|da(ñ|n)ado|calidad|beschadigd|kleur|kwaliteit)/iu',
		);
		/**
		 * Filters classifier patterns.
		 *
		 * @param array $p reason => regex.
		 */
		return apply_filters( 'dev3sg_classifier_patterns', $p );
	}

	/**
	 * Classifies a reason text: explicit phrases first ("too small", "size up"), then single words.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function classify( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		if ( '' === $text ) {
			return 'unknown';
		}
		$p     = self::patterns();
		$small = (bool) preg_match( $p['too_small'], $text );
		$large = (bool) preg_match( $p['too_large'], $text );
		if ( $small && $large ) {
			return 'unknown';
		}
		if ( $small ) {
			return 'too_small';
		}
		if ( $large ) {
			return 'too_large';
		}
		if ( preg_match( $p['fit_ok'], $text ) ) {
			return 'fit_ok';
		}
		if ( preg_match( $p['other'], $text ) ) {
			return 'other';
		}
		$small = (bool) preg_match( $p['weak_small'], $text );
		$large = (bool) preg_match( $p['weak_large'], $text );
		if ( $small xor $large ) {
			return $small ? 'too_small' : 'too_large';
		}
		return 'unknown';
	}
}
