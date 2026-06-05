<?php
/**
 * Shop by Therum — Money value object.
 *
 * Every monetary amount in Shop is an instance of this class. Internally it
 * stores integer minor units (cents for USD, yen for JPY, etc.) and a 3-letter
 * currency code. No floats, no double-precision-rounding errors.
 *
 * Construction:
 *   Money::cents(2999, 'USD')          → $29.99
 *   Money::zero('USD')                  → $0.00
 *   Money::fromMajor('29.99', 'USD')   → $29.99 (decimal string parse)
 *
 * Arithmetic:
 *   $a->plus($b)        $a->minus($b)        $a->times(2)
 *   $a->dividedBy(3)    $a->percent(15)      $a->negate()
 *
 * Comparison: equals(), lessThan(), greaterThan(), isZero(), isNegative()
 *
 * All ops between two Money instances require the same currency — mixing
 * USD and EUR throws. This is intentional.
 */

namespace Shop;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Money {

	private function __construct(
		public readonly int $minor,
		public readonly string $currency,
	) {}

	// ─── Constructors ────────────────────────────────────────────────────

	public static function cents( int $minor, string $currency = 'USD' ): self {
		return new self( $minor, strtoupper( $currency ) );
	}

	public static function zero( string $currency = 'USD' ): self {
		return new self( 0, strtoupper( $currency ) );
	}

	/**
	 * Parse a decimal string like "29.99" into minor units. Strict — won't
	 * accept floats (precision risk). The number of decimal places must
	 * match the currency's subunit count (2 for USD/EUR, 0 for JPY).
	 */
	public static function fromMajor( string $amount, string $currency = 'USD' ): self {
		$currency = strtoupper( $currency );
		$places   = self::subunitDigits( $currency );

		if ( ! preg_match( '/^-?\d+(?:\.\d+)?$/', $amount ) ) {
			throw new \InvalidArgumentException( "Money::fromMajor expects a decimal string, got: {$amount}" );
		}

		$negative = str_starts_with( $amount, '-' );
		$amount   = ltrim( $amount, '-' );

		[ $whole, $frac ] = array_pad( explode( '.', $amount, 2 ), 2, '' );
		$frac = substr( str_pad( $frac, $places, '0' ), 0, $places );

		$minor = (int) ( $whole . $frac );
		return new self( $negative ? -$minor : $minor, $currency );
	}

	// ─── Arithmetic ──────────────────────────────────────────────────────

	public function plus( self $other ): self {
		$this->assertSameCurrency( $other );
		return new self( $this->minor + $other->minor, $this->currency );
	}

	public function minus( self $other ): self {
		$this->assertSameCurrency( $other );
		return new self( $this->minor - $other->minor, $this->currency );
	}

	public function times( int $factor ): self {
		return new self( $this->minor * $factor, $this->currency );
	}

	/**
	 * Divide by an integer, rounding half-to-even (banker's rounding —
	 * statistically unbiased for repeated rounding).
	 */
	public function dividedBy( int $divisor, int $mode = PHP_ROUND_HALF_EVEN ): self {
		if ( $divisor === 0 ) {
			throw new \DivisionByZeroError( 'Money::dividedBy by zero' );
		}
		$result = (int) round( $this->minor / $divisor, 0, $mode );
		return new self( $result, $this->currency );
	}

	/**
	 * Calculate a percentage of this amount. 15.0 = 15%. Half-to-even rounding.
	 */
	public function percent( float $percent ): self {
		$result = (int) round( $this->minor * $percent / 100.0, 0, PHP_ROUND_HALF_EVEN );
		return new self( $result, $this->currency );
	}

	public function negate(): self {
		return new self( -$this->minor, $this->currency );
	}

	public function absoluteValue(): self {
		return new self( abs( $this->minor ), $this->currency );
	}

	// ─── Comparison ──────────────────────────────────────────────────────

	public function equals( self $other ): bool {
		return $this->currency === $other->currency && $this->minor === $other->minor;
	}

	public function lessThan( self $other ): bool {
		$this->assertSameCurrency( $other );
		return $this->minor < $other->minor;
	}

	public function greaterThan( self $other ): bool {
		$this->assertSameCurrency( $other );
		return $this->minor > $other->minor;
	}

	public function isZero(): bool       { return $this->minor === 0; }
	public function isNegative(): bool   { return $this->minor < 0; }
	public function isPositive(): bool   { return $this->minor > 0; }

	// ─── Display ─────────────────────────────────────────────────────────

	/**
	 * Format as a localized currency string. Uses ext-intl if available,
	 * falls back to a simple "$29.99" format.
	 */
	public function format( ?string $locale = null ): string {
		$locale = $locale ?? get_locale();

		if ( class_exists( \NumberFormatter::class ) ) {
			$fmt = new \NumberFormatter( $locale, \NumberFormatter::CURRENCY );
			return $fmt->formatCurrency( $this->toMajor(), $this->currency );
		}

		return $this->currency . ' ' . number_format( $this->toMajor(), self::subunitDigits( $this->currency ) );
	}

	/**
	 * Decimal-major-unit float for display ONLY. Never store this.
	 */
	public function toMajor(): float {
		$divisor = 10 ** self::subunitDigits( $this->currency );
		return $this->minor / $divisor;
	}

	public function __toString(): string {
		return $this->format();
	}

	// ─── Internal ────────────────────────────────────────────────────────

	private function assertSameCurrency( self $other ): void {
		if ( $this->currency !== $other->currency ) {
			throw new \InvalidArgumentException(
				"Cannot mix currencies: {$this->currency} vs {$other->currency}"
			);
		}
	}

	/**
	 * Subunit digit count per ISO 4217. Most are 2; a few exceptions matter.
	 */
	private static function subunitDigits( string $currency ): int {
		return match ( strtoupper( $currency ) ) {
			'JPY', 'KRW', 'VND', 'CLP', 'ISK' => 0,
			'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' => 3,
			default => 2,
		};
	}
}
