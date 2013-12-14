<?php

namespace Litipk\BigNumbers;

use Litipk\BigNumbers\BigNumber as BigNumber;
use Litipk\BigNumbers\IComparableNumber as IComparableNumber;
use Litipk\BigNumbers\AbelianAdditiveGroup as AbelianAdditiveGroup;

use Litipk\BigNumbers\NaN as NaN;
use Litipk\BigNumbers\Infinite as Infinite;

/**
 * Immutable object that represents a rational number
 * 
 * @author Andreu Correa Casablanca <castarco@litipk.com>
 */
final class Decimal implements BigNumber, IComparableNumber, AbelianAdditiveGroup
{
	/**
	 * Internal numeric value
	 * @var string
	 */
	private $value;

	/**
	 * Number of digits behind the point
	 * @var integer
	 */
	private $scale;

	/**
	 * Private constructor
	 */
	private function __construct () {}
	private function __clone () {}

	/**
	 * Decimal "constructor".
	 * 
	 * @param mixed   $value
	 * @param integer $scale
	 */
	public static function create ($value, $scale = null)
	{
		if (is_int(($value))) {
			return self::fromInteger($value, $scale);
		} elseif (is_float($value)) {
			return self::fromFloat($value, $scale);
		} elseif (is_string($value)) {
			return self::fromString($value, $scale);
		} elseif ($value instanceof Decimal) {
			return self::fromDecimal($value, $scale);
		} else {
			throw new \InvalidArgumentException('Invalid type');
		}
	}

	/**
	 * @param  integer $intValue
	 * @param  integer $scale
	 * @return Decimal
	 */
	public static function fromInteger ($intValue, $scale = null)
	{
		self::internalConstructorValidation($intValue, $scale);

		if (!is_int($intValue)) {
			throw new \InvalidArgumentException('$intValue must be an int');
		}

		$decimal = new Decimal();

		$decimal->scale = $scale === null ? 0 : $scale;
		$decimal->value = $scale === null ?
			(string)$intValue : bcadd((string)$intValue, '0', $scale);

		return $decimal;
	}

	/**
	 * @param  float   $fltValue
	 * @param  integer $scale
	 * @return Decimal
	 */
	public static function fromFloat ($fltValue, $scale = null)
	{
		self::internalConstructorValidation($fltValue, $scale);

		if (!is_float($fltValue)) {
			throw new \InvalidArgumentException('$fltValue must be a float');
		}

		if ($fltValue === INF) {
			return Infinite::getPositiveInfinite();
		} elseif ($fltValue === -INF) {
			return Infinite::getNegativeInfinite();
		} elseif (is_nan($fltValue)) {
			return NaN::getNaN();
		}

		$decimal = new Decimal();

		$decimal->value = number_format($fltValue, $scale === null ? 8 : $scale, '.', '');
		$decimal->scale = $scale === null ? 8 : $scale;

		return $decimal;
	}

	/**
	 * @param  string  $strValue
	 * @param  integer $scale
	 * @return Decimal
	 */
	public static function fromString ($strValue, $scale = null)
	{
		self::internalConstructorValidation($strValue, $scale);

		if (!is_string($strValue)) {
			throw new \InvalidArgumentException('$strValue must be a string');
		}

		if (preg_match('/^([+\-]?)0*(([1-9][0-9]*|[0-9])(\.[0-9]+)?)$/', $strValue, $captures) === 1) {

			// Now it's time to strip leading zeros in order to normalize inner values
			$sign      = ($captures[1]==='') ? '+' : $captures[1];
			$value     =  $captures[2];

			$dec_scale = $scale !== null ?
				$scale :
				(isset($captures[4]) ? max(0, strlen($captures[4])-1) : 0);

		} elseif (preg_match('/([+\-]?)([0-9](\.[0-9]+)?)[eE]([+\-]?)([1-9][0-9]*)/', $strValue, $captures) === 1) {

			// Now it's time to "unroll" the exponential notation to basic positional notation
			$sign     = ($captures[1]==='') ? '+' : $captures[1];
			$mantissa = $captures[2];

			$mantissa_scale = strlen($captures[3]) > 0 ? strlen($captures[3])-1 : 0;

			$exp_sign = ($captures[4]==='') ? '+' : $captures[4];
			$exp_val  = (int)$captures[5];

			if ($exp_sign === '+') {
				$min_scale      = ($mantissa_scale-$exp_val > 0) ? $mantissa_scale-$exp_val : 0;
				$tmp_multiplier = bcpow(10, $exp_val);
			} else {
				$min_scale      = $mantissa_scale + $exp_val;
				$tmp_multiplier = bcpow(10, -$exp_val, $exp_val);
			}

			$value     = bcmul($mantissa, $tmp_multiplier, max($min_scale, $scale !== null ? $scale : 0));
			$dec_scale = $scale !== null ? $scale : $min_scale;

		} else {
			throw new \InvalidArgumentException('$strValue must be a string that represents uniquely a float point number');
		}

		if ($sign === '-') {
			$value = '-'.$value;
		}

		if ($scale !== null) {
			$value = self::innerRound($value, $scale);
		}

		$decimal = new Decimal();

		$decimal->value = $value;
		$decimal->scale = $dec_scale;

		return $decimal;
	}

	/**
	 * Constructs a new Decimal object based on a previous one,
	 * but changing it's $scale property.
	 * 
	 * @param  Decimal  $decValue
	 * @param  integer  $scale
	 * @return Decimal
	 */
	public static function fromDecimal (Decimal $decValue, $scale = null)
	{
		self::internalConstructorValidation($decValue, $scale);

		// This block protect us from unnecessary additional instances
		if ($scale === null || $scale === $decValue->scale) {
			return $decValue;
		}

		$decimal = new Decimal();

		$decimal->value = self::innerRound($decValue->value, $scale);
		$decimal->scale = $scale;

		return $decimal;
	}

	/**
	 * Adds two Decimal objects
	 * @param  BigNumber $b
	 * @param  integer $scale
	 * @return BigNumber
	 */
	public function add (BigNumber $b, $scale = null)
	{
		self::internalOperatorValidation($b, $scale);

		if ($b instanceof Decimal) {
			return self::fromString(bcadd($this->value, $b->value, max($this->scale, $b->scale)), $scale);
		} else {
			// Hack to support new unknown classes. We use the commutative property
			return $b->add($this);
		}
	}

	/**
	 * Subtracts two BigNumber objects
	 * @param  BigNumber $b
	 * @param  integer $scale
	 * @return BigNumber
	 */
	public function sub (BigNumber $b, $scale = null)
	{
		self::internalOperatorValidation($b, $scale);

		if ($b->isNaN()) {
			return $b;
		} elseif ($b->isInfinite() && $b->isPositive()) {
			return Infinite::getNegativeInfinite();
		} elseif ($b->isInfinite() && $b->isNegative()) {
			return Infinite::getPositiveInfinite();
		} elseif ($b instanceof Decimal) {
			if ($this->equals($b, $scale)) {
				return self::fromInteger(0, $scale !== null ?
					$scale : max($this->scale, $b->scale)
				);
			}

			return self::fromString(bcsub($this->value, $b->value, max($this->scale, $b->scale)), $scale);
		} else {
			if ($b instanceof AbelianAdditiveGroup) {
				
				if ($this->isZero()) {
					return $b->additiveInverse();
				} else {
					// Hack to support new unknown classes.
					return $b->additiveInverse()->add($this);
				}
			}
			// @TODO : Throw a "not implemented exception"
		}
	}

	/**
	 * Multiplies two BigNumber objects
	 * @param  BigNumber $b
	 * @param  integer $scale
	 * @return BigNumber
	 */
	public function mul (BigNumber $b, $scale = null)
	{
		self::internalOperatorValidation($b, $scale);

		if ($b instanceof Decimal) {
			return self::fromString(bcmul($this->value, $b->value, $this->scale + $b->scale), $scale);
		} else {
			// Hack to support new unknown classes. We use the commutative property
			return $b->mul($this);
		}
	}

	/**
	 * Divides the object by $b
	 * @param  BigNumber $b
	 * @param  integer $scale
	 * @return BigNumber
	 */
	public function div (BigNumber $b, $scale = null)
	{
		self::internalOperatorValidation($b, $scale);

		if ($b->isNaN()) {
			return $b;
		} elseif ($b->isZero()) {
			return NaN::getNaN();
		} elseif ($this->isZero()) {
			return self::fromDecimal($this, $scale);
		} elseif ($b->isInfinite()) {
			return self::fromInteger(0, $scale !== null ?
				$scale : $this->scale
			);
		} elseif ($b instanceof Decimal) {
			
			if (max($this->scale, $b->scale) === 0) {
				if ($this->value >= $b->value) {
					$divscale = 2;
				} else {
					$divscale = (int)ceil(log10(abs($b->value)) - log10(abs($this->value))) + 2;
				}
			} else {
				$divscale = max(2, (int)ceil(log10(abs($b->value)) - log10(abs($this->value))) + 2, $this->scale + $b->scale);
			}

			$divscale = $scale !== null ? max($scale, $divscale) : $divscale;

			return self::fromString(
				bcdiv($this->value, $b->value, $divscale), $scale
			);
		} else {
			// @TODO : Throw a "not implemented exception" 
		}
	}

	/**
	 * @return boolean
	 */
	public function isZero ($scale = null) {
		$cmp_scale = $scale !== null ? $scale : $this->scale;

		return (bccomp(self::innerRound($this->value, $cmp_scale), '0', $cmp_scale) === 0);
	}

	/**
	 * @return boolean
	 */
	public function isPositive ()
	{
		return ($this->value[0] !== '-');
	}

	/**
	 * @return boolean
	 */
	public function isNegative ()
	{
		return ($this->value[0] === '-');
	}

	/**
	 * @return boolean
	 */
	public function isInfinite ()
	{
		return false;
	}

	/**
	 * Says if this object is a "Not a Number"
	 * @return boolean
	 */
	public function isNaN ()
	{
		return false;
	}

	/**
	 * Equality comparison between this object and $b
	 * @param  BigNumber $b
	 * @return boolean
	 */
	public function equals (BigNumber $b, $scale = null)
	{
		self::internalOperatorValidation($b, $scale);

		if ($this === $b) {
			return true;
		} elseif ($b instanceof Decimal) {
			$cmp_scale = $scale !== null ? $scale : max($this->scale, $b->scale);

			return (
				bccomp(
					self::innerRound($this->value, $cmp_scale), self::innerRound($b->value, $cmp_scale),
					$cmp_scale
				) == 0
			);
		} else {
			return $b->equals($this);
		}
	}

	/**
	 * $this > $b : returns 1 , $this < $b : returns -1 , $this == $b : returns 0
	 * 
	 * @param  IComparableNumber $b
	 * @return integer
	 */
	public function comp (IComparableNumber $b, $scale = null)
	{
		self::internalOperatorValidation($b, $scale);

		if ($b === $this) {
			return 0;
		} elseif ($b instanceof Decimal) {
			return bccomp(self::innerRound($this->value, $scale), self::innerRound($b->value, $scale), $scale);
		} else {
			return -$b->comp($this);
		}
	}

	/**
	 * Returns the element's additive inverse.
	 * @return Decimal
	 */
	public function additiveInverse ()
	{
		if ($this->isZero()) {
			return $this;
		}

		$decimal = new Decimal();

		if ($this->isNegative()) {
			$decimal->value = substr($this->value, 1);
		} elseif ($this->isPositive()) {
			$decimal->value = '-' . $this->value;
		}

		$decimal->scale = $this->scale;

		return $decimal;
	}

	/**
	 * "Rounds" the Decimal to have at most $scale digits after the point
	 * @param  integer $scale
	 * @return Decimal
	 */
	public function round ($scale = 0) {
		if ($scale >= $this->scale) {
			return $this;
		}

		return self::fromString(self::innerRound($this->value, $scale));
	}

	/**
	 * Returns the absolute value (always a positive number)
	 * @return Decimal
	 */
	public function abs () {
		if ($this->isZero() || $this->isPositive()) {
			return $this;
		}
		
		return $this->additiveInverse();
	}

	/**
	 * @return string
	 */
	public function __toString ()
	{
		return $this->value;
	}

	/**
	 * Validates basic constructor's arguments
	 * @param  mixed    $value
	 * @param  integer  $scale
	 */
	private static function internalConstructorValidation ($value, $scale)
	{
		if ($value === null) {
			throw new \InvalidArgumentException('$value must be a non null number');
		}

		if ($scale !== null && (!is_int($scale) || $scale < 0)) {
			throw new \InvalidArgumentException('$scale must be a positive integer');
		}
	}

	/**
	 * Validates basic operator's arguments
	 * @param  Decimal  $b      operand
	 * @param  integer  $scale  bcmath scale param
	 */
	private static function internalOperatorValidation (BigNumber $b, $scale)
	{
		if ($b === null) {
			throw new \InvalidArgumentException('$b must be not null');
		}

		if ($scale !== null && (!is_int($scale) || $scale < 0)) {
			throw new \InvalidArgumentException('$scale must be a positive integer');
		}
	}

	/**
	 * "Rounds" the decimal string to have at most $scale digits after the point
	 * 
	 * @param  string  $value
	 * @param  integer $scale
	 * @return string
	 */
	private static function innerRound ($value, $scale = 0)
	{
		$rounded = bcadd($value, '0', $scale);

		$diffDigit = bcsub($value, $rounded, $scale+1);
		$diffDigit = (int)$diffDigit[strlen($diffDigit)-1];

		if ($diffDigit >= 5) {
			$rounded = bcadd($rounded, bcpow('10', -$scale, $scale), $scale);
		}

		return $rounded;
	}
}
