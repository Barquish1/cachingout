<?php
/**
 * Plain-English explanation of a chokepoint `BYPASS:` reason (1.27.0).
 *
 * The chokepoint decides and names the rule; this class only phrases it.
 * It holds no cache-or-not condition. The templates live in
 * CachingOut_Bypass_Templates; this class matches a reason code to one and
 * fills in the parameter (`BYPASS:cookie:<name>` names the cookie).
 *
 * @package CachingOut
 * @since 1.27.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bypass reason explainer.
 *
 * @since 1.27.0
 */
class CachingOut_Bypass_Explainer {

	/**
	 * The `code` an explanation carries when no template matched.
	 *
	 * @var string
	 */
	const UNKNOWN = 'unknown';

	/**
	 * Explain one bypass reason.
	 *
	 * Matching: the exact code first, then the longest template key that
	 * ends in `:` and prefixes the code (`BYPASS:set-cookie:store:x` matches
	 * the store template, not the generic set-cookie one). The text after a
	 * prefix is the parameter and replaces `%s` in the template. A code no
	 * template covers gets the generic template with the code itself as
	 * the parameter: never an empty explanation.
	 *
	 * @since 1.27.0
	 *
	 * @param string $reason A `BYPASS:...` code as the chokepoint returns it.
	 * @return array{code: string, title: string, meaning: string, correct: bool|null, fix: string, doc: string}
	 *               `code` is the template key matched, or self::UNKNOWN.
	 */
	public static function explain( string $reason ): array {
		$reason    = trim( $reason );
		$templates = CachingOut_Bypass_Templates::all();
		$key       = self::match( $reason, $templates );

		if ( null === $key ) {
			return self::fill( self::UNKNOWN, CachingOut_Bypass_Templates::generic(), $reason );
		}

		$parameter = ':' === substr( $key, -1 ) ? substr( $reason, strlen( $key ) ) : '';

		return self::fill( $key, $templates[ $key ], $parameter );
	}

	/**
	 * Whether a specific (non-generic) template covers a reason.
	 *
	 * A prefix literal such as `BYPASS:cookie:` counts as covered when the
	 * prefix template exists.
	 *
	 * @since 1.27.0
	 *
	 * @param string $reason Reason code or prefix literal.
	 * @return bool
	 */
	public static function has_template( string $reason ): bool {
		$templates = CachingOut_Bypass_Templates::all();
		if ( isset( $templates[ $reason ] ) ) {
			return true;
		}
		return null !== self::match( $reason . 'x', $templates );
	}

	/**
	 * Every template key, for tests and the assistant's reason catalogue.
	 *
	 * @since 1.27.0
	 * @return string[]
	 */
	public static function codes(): array {
		return array_keys( CachingOut_Bypass_Templates::all() );
	}

	/**
	 * The template key for a reason, or null.
	 *
	 * @since 1.27.0
	 *
	 * @param string $reason    Reason code.
	 * @param array  $templates Template catalogue.
	 * @return string|null
	 */
	private static function match( string $reason, array $templates ): ?string {
		if ( isset( $templates[ $reason ] ) ) {
			return $reason;
		}

		$best = null;
		foreach ( array_keys( $templates ) as $key ) {
			if ( ':' !== substr( $key, -1 ) || 0 !== strpos( $reason, $key ) ) {
				continue;
			}
			if ( null === $best || strlen( $key ) > strlen( $best ) ) {
				$best = $key;
			}
		}

		return $best;
	}

	/**
	 * Substitute the parameter into a template's text.
	 *
	 * @since 1.27.0
	 *
	 * @param string $code      Template key or self::UNKNOWN.
	 * @param array  $template  Template (title, meaning, correct, fix, doc).
	 * @param string $parameter The text that replaces `%s`.
	 * @return array{code: string, title: string, meaning: string, correct: bool|null, fix: string, doc: string}
	 */
	private static function fill( string $code, array $template, string $parameter ): array {
		return array(
			'code'    => $code,
			'title'   => str_replace( '%s', $parameter, $template['title'] ),
			'meaning' => str_replace( '%s', $parameter, $template['meaning'] ),
			'correct' => $template['correct'],
			'fix'     => str_replace( '%s', $parameter, $template['fix'] ),
			'doc'     => $template['doc'],
		);
	}
}
