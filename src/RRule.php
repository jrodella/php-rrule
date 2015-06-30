<?php

/**
 * Licensed under the MIT license.
 *
 * For the full copyright and license information, please view the LICENSE file.
 *
 * @author Rémi Lanvin <remi@cloudconnected.fr>
 * @link https://github.com/rlanvin/php-rrule
 */

namespace RRule;

/**
 * Check that a variable is not empty. 0 and '0' are considered NOT empty
 *
 * @return bool
 */
function not_empty($var)
{
	return ! empty($var) || $var === 0 || $var === '0';
}

/**
 * closure/goog/math/math.js:modulo
 * Copyright 2006 The Closure Library Authors.
 *
 * The % operator in PHP returns the remainder of a / b, but differs from
 * some other languages in that the result will have the same sign as the
 * dividend. For example, -1 % 8 == -1, whereas in some other languages
 * (such as Python) the result would be 7. This function emulates the more
 * correct modulo behavior, which is useful for certain applications such as
 * calculating an offset index in a circular list.
 *
 * @param int $a The dividend.
 * @param int $b The divisor.
 *
 * @return int $a % $b where the result is between 0 and $b
 *   (either 0 <= x < $b
 *     or $b < x <= 0, depending on the sign of $b).
 */
function pymod($a, $b)
{
	$x = $a % $b;

	// If $x and $b differ in sign, add $b to wrap the result to the correct sign.
	return ($x * $b < 0) ? $x + $b : $x;
}

/**
 * Check is a year is a leap year.
 * @return bool
 */
function is_leap_year($year)
{
	if ( $year % 4 !== 0 ) {
		return false;
	}
	if ( $year % 100 !== 0 ) {
		return true;
	}
	if ( $year % 400 !== 0 ) {
		return false;
	}
	return true;
}

/**
 * Calculate the Greatest Common Divisor of a and b.
 * Unless b==0, the result will have the same sign as b (so that when
 * b is divided by it, the result comes out positive).
 */
function gcd($a, $b)
{
	while ($b) {
		list($a, $b) = array($b, $a % $b);
	}
	return $a;
}

/**
 * Implementation of RRULE as defined by RFC 5545.
 * Heavily based on python-dateutil/rrule
 *
 * Some useful terms to understand the algorithms and variables naming:
 *
 * yearday = day of the year, from 0 to 365 (on leap years) - date('z')
 * weekday = day of the week (ISO-8601), from 1 (MO) to 7 (SU) - date('N')
 * monthday = day of the month, from 1 to 31
 * wkst = week start, the weekday (1 to 7) which is the first day of week.
 *        Default is Monday (1). In some countries it's Sunday (7).
 * weekno = number of the week in the year (ISO-8601)
 *
 * CAREFUL with this bug: https://bugs.php.net/bug.php?id=62476
 *
 * @see https://tools.ietf.org/html/rfc5545
 * @see https://labix.org/python-dateutil
 */
class RRule implements \Iterator, \ArrayAccess
{
	const SECONDLY = 7;
	const MINUTELY = 6;
	const HOURLY = 5;
	const DAILY = 4;
	const WEEKLY = 3;
	const MONTHLY = 2;
	const YEARLY = 1;

	// frequency names
	public static $frequencies = array(
		'SECONDLY' => self::SECONDLY,
		'MINUTELY' => self::MINUTELY,
		'HOURLY' => self::HOURLY,
		'DAILY' => self::DAILY,
		'WEEKLY' => self::WEEKLY,
		'MONTHLY' => self::MONTHLY,
		'YEARLY' => self::YEARLY
	);

	// weekdays numbered from 1 (ISO-8601 or date('N'))
	public static $week_days = array(
		'MO' => 1,
		'TU' => 2,
		'WE' => 3,
		'TH' => 4,
		'FR' => 5,
		'SA' => 6,
		'SU' => 7
	);

	// original rule
	protected $rule = array(
		'DTSTART' => null,
		'FREQ' => null,
		'UNTIL' => null,
		'COUNT' => null,
		'INTERVAL' => 1,
		'BYSECOND' => null,
		'BYMINUTE' => null,
		'BYHOUR' => null,
		'BYDAY' => null,
		'BYMONTHDAY' => null,
		'BYYEARDAY' => null,
		'BYWEEKNO' => null,
		'BYMONTH' => null,
		'BYSETPOS' => null,
		'WKST' => 'MO'
	);

	// parsed and validated values
	protected $dtstart = null;
	protected $freq = null;
	protected $until = null;
	protected $count = null;
	protected $interval = null;
	protected $bysecond = null;
	protected $byminute = null;
	protected $byhour = null;
	protected $byweekday = null;
	protected $byweekday_nth = null;
	protected $bymonthday = null;
	protected $bymonthday_negative = null;
	protected $byyearday = null;
	protected $byweekno = null;
	protected $bymonth = null;
	protected $bysetpos = null;
	protected $wkst = null;
	protected $timeset = null;

// Public interface

	/**
	 * The constructor needs the entire rule at once.
	 * There is no setter after the class has been instanciated,
	 * because in order to validate some BYXXX parts, we need to know
	 * the value of some other parts (FREQ or other BXXX parts).
	 */
	public function __construct(array $parts)
	{
		$parts = array_change_key_case($parts, CASE_UPPER);

		// validate extra parts
		$unsupported = array_diff_key($parts, $this->rule);
		if ( ! empty($unsupported) ) {
			throw new \InvalidArgumentException(
				'Unsupported parameter(s): '
				.implode(',',array_keys($unsupported))
			);
		}

		$parts = array_merge($this->rule, $parts);
		$this->rule = $parts; // save original rule

		// WKST
		$parts['WKST'] = strtoupper($parts['WKST']);
		if ( ! array_key_exists($parts['WKST'], self::$week_days) ) {
			throw new \InvalidArgumentException(
				'The WKST rule part must be one of the following: '
				.implode(', ',array_keys(self::$week_days))
			);
		}
		$this->wkst = self::$week_days[$parts['WKST']];

		// FREQ
		$parts['FREQ'] = strtoupper($parts['FREQ']);
		if ( (is_int($parts['FREQ']) && ($parts['FREQ'] < self::SECONDLY || $parts['FREQ'] > self::YEARLY))
			|| ! array_key_exists($parts['FREQ'], self::$frequencies) ) {
			throw new \InvalidArgumentException(
				'The FREQ rule part must be one of the following: '
				.implode(', ',array_keys(self::$frequencies))
			);
		}
		$this->freq = self::$frequencies[$parts['FREQ']];

		// INTERVAL
		$parts['INTERVAL'] = (int) $parts['INTERVAL'];
		if ( $parts['INTERVAL'] < 1 ) {
			throw new \InvalidArgumentException(
				'The INTERVAL rule part must be a positive integer (> 0)'
			);
		}
		$this->interval = (int) $parts['INTERVAL'];

		// DTSTART
		if ( not_empty($parts['DTSTART']) ) {
			if ( $parts['DTSTART'] instanceof \DateTime ) {
				$this->dtstart = $parts['DTSTART'];
			}
			else {
				try {
					if ( is_integer($parts['DTSTART']) ) {
						$this->dtstart = \DateTime::createFromFormat('U',$parts['DTSTART']);
					}
					else {
						$this->dtstart = new \DateTime($parts['DTSTART']);
					}
				} catch (\Exception $e) {
					throw new \InvalidArgumentException(
						'Failed to parse DTSTART ; it must be a valid date, timestamp or \DateTime object'
					);
				}
			}
		} 
		else {
			$this->dtstart = new \DateTime();
		}

		// UNTIL (optional)
		if ( not_empty($parts['UNTIL']) ) {
			if ( $parts['UNTIL'] instanceof \DateTime ) {
				$this->until = $parts['UNTIL'];
			}
			else {
				try {
					if ( is_integer($parts['UNTIL']) ) {
						$this->until = \DateTime::createFromFormat('U',$parts['UNTIL']);
					}
					else {
						$this->until = new \DateTime($parts['UNTIL']);
					}
				} catch (\Exception $e) {
					throw new \InvalidArgumentException(
						'Failed to parse UNTIL ; it must be a valid date, timestamp or \DateTime object'
					);
				}
			}
		}

		// COUNT (optional)
		if ( not_empty($parts['COUNT']) ) {
			$parts['COUNT'] = (int) $parts['COUNT'];
			if ( $parts['COUNT'] < 1 ) {
				throw new \InvalidArgumentException('COUNT must be a positive integer (> 0)');
			}
			$this->count = (int) $parts['COUNT'];
		}

		// infer necessary BYXXX rules from DTSTART, if not provided
		if ( ! (not_empty($parts['BYWEEKNO']) || not_empty($parts['BYYEARDAY']) || not_empty($parts['BYMONTHDAY']) || not_empty($parts['BYDAY'])) ) {
			switch ( $this->freq ) {
				case self::YEARLY:
					if ( ! not_empty($parts['BYMONTH']) ) {
						$parts['BYMONTH'] = array((int) $this->dtstart->format('m'));
					}
					$parts['BYMONTHDAY'] = array((int) $this->dtstart->format('j'));
					break;
				case self::MONTHLY:
					$parts['BYMONTHDAY'] = array((int) $this->dtstart->format('j'));
					break;
				case self::WEEKLY:
					$parts['BYDAY'] = array(array_search($this->dtstart->format('N'), self::$week_days));
					break;
			}
		}

		// BYDAY (translated to byweekday for convenience)
		if ( not_empty($parts['BYDAY']) ) {
			if ( ! is_array($parts['BYDAY']) ) {
				$parts['BYDAY'] = explode(',',$parts['BYDAY']);
			}
			$this->byweekday = array();
			$this->byweekday_nth = array();
			foreach ( $parts['BYDAY'] as $value ) {
				$value = trim($value);
				$valid = preg_match('/^([+-]?[0-9]+)?([A-Z]{2})$/', $value, $matches);
				if ( ! $valid || (not_empty($matches[1]) && ($matches[1] == 0 || $matches[1] > 53 || $matches[1] < -53)) || ! array_key_exists($matches[2], self::$week_days) ) {
					throw new \InvalidArgumentException('Invalid BYDAY value: '.$value);
				}

				if ( $matches[1] ) {
					$this->byweekday_nth[] = array(self::$week_days[$matches[2]], (int)$matches[1]);
				}
				else {
					$this->byweekday[] = self::$week_days[$matches[2]];
				}
			}

			if ( ! empty($this->byweekday_nth) ) {
				if ( ! ($this->freq === self::MONTHLY || $this->freq === self::YEARLY) ) {
					throw new \InvalidArgumentException('The BYDAY rule part MUST NOT be specified with a numeric value when the FREQ rule part is not set to MONTHLY or YEARLY.');
				}
				if ( $this->freq === self::YEARLY && not_empty($parts['BYWEEKNO']) ) {
					throw new \InvalidArgumentException('The BYDAY rule part MUST NOT be specified with a numeric value with the FREQ rule part set to YEARLY when the BYWEEKNO rule part is specified.');
				}
			}
		}

		// The BYMONTHDAY rule part specifies a COMMA-separated list of days
		// of the month.  Valid values are 1 to 31 or -31 to -1.  For
		// example, -10 represents the tenth to the last day of the month.
		// The BYMONTHDAY rule part MUST NOT be specified when the FREQ rule
		// part is set to WEEKLY.
		if ( not_empty($parts['BYMONTHDAY']) ) {
			if ( $this->freq === self::WEEKLY ) {
				throw new \InvalidArgumentException('The BYMONTHDAY rule part MUST NOT be specified when the FREQ rule part is set to WEEKLY.');
			}

			if ( ! is_array($parts['BYMONTHDAY']) ) {
				$parts['BYMONTHDAY'] = explode(',',$parts['BYMONTHDAY']);
			}

			$this->bymonthday = array();
			$this->bymonthday_negative = array();
			foreach ( $parts['BYMONTHDAY'] as $value ) {
				if ( ! $value || $value < -31 || $value > 31 ) {
					throw new \InvalidArgumentException('Invalid BYMONTHDAY value: '.$value.' (valid values are 1 to 31 or -31 to -1)');
				}
				if ( $value < 0 ) {
					$this->bymonthday_negative[] = (int) $value;
				}
				else {
					$this->bymonthday[] = (int) $value;
				}
			}
		}

		if ( not_empty($parts['BYYEARDAY']) ) {
			if ( $this->freq === self::DAILY || $this->freq === self::WEEKLY || $this->freq === self::MONTHLY ) {
				throw new \InvalidArgumentException('The BYYEARDAY rule part MUST NOT be specified when the FREQ rule part is set to DAILY, WEEKLY, or MONTHLY.');
			}

			if ( ! is_array($parts['BYYEARDAY']) ) {
				$parts['BYYEARDAY'] = explode(',',$parts['BYYEARDAY']);
			}

			$this->bysetpos = array();
			foreach ( $parts['BYYEARDAY'] as $value ) {
				if ( ! $value || $value < -366 || $value > 366 ) {
					throw new \InvalidArgumentException('Invalid BYSETPOS value: '.$value.' (valid values are 1 to 366 or -366 to -1)');
				}

				$this->byyearday[] = (int) $value;
			}
		}

		// BYWEEKNO
		if ( not_empty($parts['BYWEEKNO']) ) {
			if ( $this->freq !== self::YEARLY ) {
				throw new \InvalidArgumentException('The BYWEEKNO rule part MUST NOT be used when the FREQ rule part is set to anything other than YEARLY.');
			}

			if ( ! is_array($parts['BYWEEKNO']) ) {
				$parts['BYWEEKNO'] = explode(',',$parts['BYWEEKNO']);
			}

			$this->byweekno = array();
			foreach ( $parts['BYWEEKNO'] as $value ) {
				if ( ! $value || $value < -53 || $value > 53 ) {
					throw new \InvalidArgumentException('Invalid BYWEEKNO value: '.$value.' (valid values are 1 to 53 or -53 to -1)');
				}
				$this->byweekno[] = (int) $value;
			}
		}

		// The BYMONTH rule part specifies a COMMA-separated list of months
		// of the year.  Valid values are 1 to 12.
		if ( not_empty($parts['BYMONTH']) ) {
			if ( ! is_array($parts['BYMONTH']) ) {
				$parts['BYMONTH'] = explode(',',$parts['BYMONTH']);
			}

			$this->bymonth = array();
			foreach ( $parts['BYMONTH'] as $value ) {
				if ( $value < 1 || $value > 12 ) {
					throw new \InvalidArgumentException('Invalid BYMONTH value: '.$value);
				}
				$this->bymonth[] = (int) $value;
			}
		}

		if ( not_empty($parts['BYSETPOS']) ) {
			if ( ! (not_empty($parts['BYWEEKNO']) || not_empty($parts['BYYEARDAY'])
				|| not_empty($parts['BYMONTHDAY']) || not_empty($parts['BYDAY'])
				|| not_empty($parts['BYMONTH']) || not_empty($parts['BYHOUR'])
				|| not_empty($parts['BYMINUTE']) || not_empty($parts['BYSECOND'])) ) {
				throw new \InvalidArgumentException('The BYSETPOS rule part MUST only be used in conjunction with another BYxxx rule part.');
			}

			if ( ! is_array($parts['BYSETPOS']) ) {
				$parts['BYSETPOS'] = explode(',',$parts['BYSETPOS']);
			}

			$this->bysetpos = array();
			foreach ( $parts['BYSETPOS'] as $value ) {
				if ( ! $value || $value < -366 || $value > 366 ) {
					throw new \InvalidArgumentException('Invalid BYSETPOS value: '.$value.' (valid values are 1 to 366 or -366 to -1)');
				}

				$this->bysetpos[] = (int) $value;
			}
		}

// now for the time options
// this gets more complicated

		if ( not_empty($parts['BYHOUR']) ) {
			if ( ! is_array($parts['BYHOUR']) ) {
				$parts['BYHOUR'] = explode(',',$parts['BYHOUR']);
			}

			$this->byhour = array();
			foreach ( $parts['BYHOUR'] as $value ) {
				if ( $value < 0 || $value > 23 ) {
					throw new \InvalidArgumentException('Invalid BYHOUR value: '.$value);
				}
				$this->byhour[] = (int) $value;
			}

			if ( $this->freq === self::HOURLY ) {
				// do something (__construct_byset) ?
			}
		}
		elseif ( $this->freq < self::HOURLY ) { 
			$this->byhour = array((int) $this->dtstart->format('G'));
		}

		if ( not_empty($parts['BYMINUTE']) ) {
			if ( ! is_array($parts['BYMINUTE']) ) {
				$parts['BYMINUTE'] = explode(',',$parts['BYMINUTE']);
			}

			$this->byminute = array();
			foreach ( $parts['BYMINUTE'] as $value ) {
				if ( $value < 0 || $value > 59 ) {
					throw new \InvalidArgumentException('Invalid BYMINUTE value: '.$value);
				}
				$this->byminute[] = (int) $value;
			}

			if ( $this->freq == self::MINUTELY ) {
				// do something
			}
		}
		elseif ( $this->freq < self::MINUTELY ) {
			$this->byminute = array((int) $this->dtstart->format('i'));
		}

		if ( not_empty($parts['BYSECOND']) ) {
			if ( ! is_array($parts['BYSECOND']) ) {
				$parts['BYSECOND'] = explode(',',$parts['BYSECOND']);
			}

			$this->bysecond = array();
			foreach ( $parts['BYSECOND'] as $value ) {
				// yes, "60" is a valid value, in (very rare) cases on leap seconds
				//  December 31, 2005 23:59:60 UTC is a valid date...
				// so is 2012-06-30T23:59:60UTC
				if ( $value < 0 || $value > 60 ) {
					throw new \InvalidArgumentException('Invalid BYSECOND value: '.$value);
				}
				$this->bysecond[] = (int) $value;
			}

			if ( $this->freq == self::SECONDLY ) {
				// do something
			}
		}
		elseif ( $this->freq < self::SECONDLY ) {
			$this->bysecond = array((int) $this->dtstart->format('s'));
		}

		if ( $this->freq < self::HOURLY ) {
			// for frequencies DAILY, WEEKLY, MONTHLY AND YEARLY, we can build
			// an array of every time of the day at which there should be an
			// occurrence - default, if no BYHOUR/BYMINUTE/BYSECOND are provided
			// is only one time, and it's the DTSTART time. This is a cached version
			// if you will, since it'll never change at these frequencies
			$this->timeset = array();
			foreach ( $this->byhour as $hour ) {
				foreach ( $this->byminute as $minute ) {
					foreach ( $this->bysecond as $second ) {
						// fixme another format?
						$this->timeset[] = array($hour,$minute,$second);
					}
				}
			}
			// FIXME sort ??
			// sort($this->timeset);
		}
	}

	/**
	 * @return array
	 */
	public function getOccurrences()
	{
		if ( ! $this->count && ! $this->until ) {
			throw new \LogicException('Cannot get all occurrences of an infinite recurrence rule.');
		}
		$res = array();
		foreach ( $this as $occurrence ) {
			$res[] = $occurrence;
		}
		return $res;
	}

	/**
	 * @return array
	 */
	public function getOccurrencesBetween($begin, $end)
	{
		$res = array();
		foreach ( $this as $occurrence ) {
			if ( $occurrence < $begin ) {
				continue;
			}
			if ( $occurrence > $end ) {
				break;
			}
			$res[] = $occurrence;
		}
		return $res;
	}

	/**
	 * Alias of occursAt
	 * Because I think both are correct in English, aren't they?
	 */
	public function occursOn($date)
	{
		return $this->occursAt($date);
	}

	/**
	 * Return true if $date is an occurrence of the rule.
	 *
	 * This method will attempt to determine the result programmatically.
	 * However depending on the BYXXX rule parts that have been set, it might
	 * not always be possible. As a last resort, this method will loop
	 * through all occurrences until $date. This will incurr some performance
	 * penalty.
	 *
	 * @return bool
	 */
	public function occursAt($date)
	{
		if ( ! $date instanceof \DateTime ) {
			try {
				if ( is_integer($date) ) {
					$date = \DateTime::createFromFormat('U',$date);
				}
				else {
					$date = new \DateTime($date);
				}
			} catch ( \Exception $e ) {
				throw new \InvalidArgumentException('Failed to parse the date');
			}
		}

		// let's start with the obvious
		if ( $date < $this->dtstart || ($this->until && $date > $this->until) ) {
			return false;
		}

		// now the BYXXX rules (expect BYSETPOS)
		if ( $this->byhour && ! in_array($date->format('G'), $this->byhour) ) {
			return false;
		}
		if ( $this->byminute && ! in_array((int) $date->format('i'), $this->byminute) ) {
			return false;
		}
		if ( $this->bysecond && ! in_array((int) $date->format('s'), $this->bysecond) ) {
			return false;
		}

		// we need some more variables before we continue
		list($year, $month, $day, $yearday, $weekday) = explode(' ',$date->format('Y n j z N'));
		$masks = array();
		$masks['weekday_of_1st_yearday'] = date('N', mktime(0,0,0,1,1,$year));
		$masks['yearday_to_weekday'] = array_slice(self::$WEEKDAY_MASK, $masks['weekday_of_1st_yearday']-1);
		if ( is_leap_year($year) ) {
			$masks['year_len'] = 366;
			$masks['last_day_of_month'] = self::$LAST_DAY_OF_MONTH_366;
		}
		else {
			$masks['year_len'] = 365;
			$masks['last_day_of_month'] = self::$LAST_DAY_OF_MONTH;
		}
		$month_len = $masks['last_day_of_month'][$month] - $masks['last_day_of_month'][$month-1];

		if ( $this->bymonth && ! in_array($month, $this->bymonth) ) {
			return false;
		}

		if ( $this->byweekday && ! in_array($weekday, $this->byweekday) ) {
			return false;
		}

		if ( $this->bymonthday || $this->bymonthday_negative ) {
			$monthday_negative = -1 * ($month_len - $day + 1);

			if ( ! in_array($day, $this->bymonthday) && ! in_array($monthday_negative, $this->bymonthday_negative) ) {
				return false;
			}
		}

		if ( $this->byyearday ) {
			// caution here, yearday starts from 0 !
			$yearday_negative = -1*($masks['year_len'] - $yearday);

			if ( ! in_array($yearday+1, $this->byyearday) && ! in_array($yearday_negative, $this->byyearday) ) {
				return false;
			}
		}

		if ( $this->byweekday_nth ) {
			// we need to summon some magic here
			$this->buildNthWeekdayMask($year, $month, $day, $masks);
			if ( ! isset($masks['yearday_is_nth_weekday'][$yearday]) ) {
				return false;
			}
		}

		if ( $this->byweekno ) {
			// more magic
			$this->buildWeeknoMask($year, $month, $day, $masks);
			if ( ! isset($masks['yearday_is_in_weekno'][$yearday]) ) {
				return false;
			}
		}

		// so now we have exhausted all the BYXXX rules (exept bysetpos),
		// we still need to consider frequency and interval
		list ($start_year, $start_month, $start_day) = explode('-',$this->dtstart->format('Y-m-d'));
		switch ( $this->freq ) {
			case self::YEARLY:
				if ( ($year - $start_year) % $this->interval !== 0 ) {
					return false;
				}
				break;
			case self::MONTHLY:
				// we need to count the number of months elapsed
				$diff = (12 - $start_month) + 12*($year - $start_year - 1) + $month;

				if ( ($diff % $this->interval) !== 0 ) {
					return false;
				}
				break;
			case self::WEEKLY:
				// count nb of days and divide by 7 to get number of weeks
				// we add some days to align dtstart with wkst
				$diff = $date->diff($this->dtstart);
				$diff = (int) (($diff->days + pymod($this->dtstart->format('N') - $this->wkst,7)) / 7);
				if ( $diff % $this->interval !== 0 ) {
					return false;
				}
				break;
			case self::DAILY:
				// count nb of days
				$diff = $date->diff($this->dtstart);
				if ( $diff->days % $this->interval !== 0 ) {
					return false;
				}
				break;
			// XXX: I'm not sure the 3 formulas below take the DST into account...
			case self::HOURLY:
				$diff = $date->diff($this->dtstart);
				$diff = $diff->h + $diff->days * 24;
				if ( $diff % $this->interval !== 0 ) {
					return false;
				}
				break;
			case self::MINUTELY:
				$diff = $date->diff($this->dtstart);
				$diff  = $diff->i + $diff->h * 60 + $diff->days * 1440;
				if ( $diff % $this->interval !== 0 ) {
					return false;
				}
				break;
			case self::SECONDLY:
				$diff = $date->diff($this->dtstart);
				// XXX does not account for leap second (should it?)
				$diff  = $diff->s + $diff->i * 60 + $diff->h * 3600 + $diff->days * 86400;
				if ( $diff % $this->interval !== 0 ) {
					return false;
				}
				break;
				throw new \Exception('Unimplemented frequency');
		}

		// now we are left with 2 rules BYSETPOS and COUNT
		//
		// - I think BYSETPOS *could* be determined without loooping by considering
		// the current set, calculating all the occurrences of the current set
		// and determining the position of $date in the result set.
		// However I'm not convinced it's worth it.
		//
		// - I don't see any way to determine COUNT programmatically, because occurrences
		// might sometimes be dropped (e.g. a 29 Feb on a normal year, or during
		// the switch to DST) and not counted in the final set

		if ( ! $this->count && ! $this->bysetpos ) {
			return true;
		}

		// so... as a fallback we have to loop
		foreach ( $this as $occurrence ) {
			if ( $occurrence == $date ) {
				return true; // lucky you!
			}
			if ( $occurrence > $date ) {
				break;
			}
		}

		// we ended the loop without finding
		return false; 
	}

// Iterator interface

	protected $position = 0;

	public function rewind()
	{
		$this->position = $this->iterate(true);
	}

	public function current()
	{
		return $this->position;
	}

	public function key()
	{
		// void
	}

	public function next()
	{
		$this->position = $this->iterate();
	}

	public function valid()
	{
		return $this->position !== null;
	}

// ArrayAccess interface

	public function offsetExists($offset)
	{
		
	}

	public function offsetGet($offset)
	{
		
	}

	public function offsetSet($offset, $value)
	{
		throw new LogicException('Setting a Date in a RRule is not supported');
	}

	public function offsetUnset($offset)
	{
		throw new LogicException('Unsetting a Date in a RRule is not supported');
	}

// private methods

	/**
	 * This method returns an array of days of the year (numbered from 0 to 365)
	 * of the current timeframe (year, month, week, day) containing the current date
	 */
	protected function getDaySet($year, $month, $day, array $masks)
	{
		switch ( $this->freq ) {
			case self::YEARLY:
				return range(0,$masks['year_len']-1);

			case self::MONTHLY:
				$start = $masks['last_day_of_month'][$month-1];
				$stop = $masks['last_day_of_month'][$month];
				return range($start, $stop - 1);

			case self::WEEKLY:
				// on first iteration, the first week will not be complete
				// we don't backtrack to the first day of the week, to avoid
				// crossing year boundary in reverse (i.e. if the week started
				// during the previous year), because that would generate
				// negative indexes (which would not work with the masks)
				$set = array();
				$i = (int) date('z', mktime(0,0,0,$month,$day,$year));
				$start = $i;
				for ( $j = 0; $j < 7; $j++ ) {
					$set[] = $i;
					$i += 1;
					if ( $masks['yearday_to_weekday'][$i] == $this->wkst ) {
						break;
					}
				}
				return $set;

			case self::DAILY:
			case self::HOURLY:
			case self::MINUTELY:
			case self::SECONDLY:
				$n = (int) date('z', mktime(0,0,0,$month,$day,$year));
				return array($n);
		}
	}

	/**
	 * Some serious magic is happening here.
	 * This method will calculate the yeardays corresponding to each Nth weekday
	 * (in BYDAY rule part).
	 * For example, in Jan 1998, in a MONTHLY interval, "1SU,-1SU" (first Sunday
	 * and last Sunday) would be transformed into [3=>true,24=>true] because
	 * the first Sunday of Jan 1998 is yearday 3 (counting from 0) and the
	 * last Sunday of Jan 1998 is yearday 24 (counting from 0).
	 */
	protected function buildNthWeekdayMask($year, $month, $day, array & $masks)
	{
		$masks['yearday_is_nth_weekday'] = array();

		if ( $this->byweekday_nth ) {
			$ranges = array();
			if ( $this->freq == self::YEARLY ) {
				if ( $this->bymonth ) {
					foreach ( $this->bymonth as $bymonth ) {
						$ranges[] = array(
							$masks['last_day_of_month'][$bymonth - 1],
							$masks['last_day_of_month'][$bymonth] - 1
						);
					}
				}
				else {
					$ranges = array(array(0, $masks['year_len'] - 1));
				}
			}
			elseif ( $this->freq == self::MONTHLY ) {
				$ranges[] = array(
					$masks['last_day_of_month'][$month - 1],
					$masks['last_day_of_month'][$month] - 1
				);
			}

			if ( $ranges ) {
				// Weekly frequency won't get here, so we may not
				// care about cross-year weekly periods.
				foreach ( $ranges as $tmp ) {
					list($first, $last) = $tmp;
					foreach ( $this->byweekday_nth as $tmp ) {
						list($weekday, $nth) = $tmp;
						if ( $nth < 0 ) {
							$i = $last + ($nth + 1) * 7;
							$i = $i - pymod($masks['yearday_to_weekday'][$i] - $weekday, 7);
						}
						else {
							$i = $first + ($nth - 1) * 7;
							$i = $i + (7 - $masks['yearday_to_weekday'][$i] + $weekday) % 7;
						}

						if ( $i >= $first && $i <= $last ) {
							$masks['yearday_is_nth_weekday'][$i] = true;
						}
					}
				}
			}
		}
	}

	/**
	 * More serious magic.
	 * This method calculates the yeardays corresponding to the week number
	 * (in the WEEKNO rule part).
	 * Because weeks can cross year boundaries (that is, week #1 can start the
	 * previous year, and week 52/53 can continue till the next year), the
	 * algorithm is quite long.
	 */
	protected function buildWeeknoMask($year, $month, $day, & $masks)
	{
		$masks['yearday_is_in_weekno'] = array();

		// calculate the index of the first wkst day of the year
		// 0 means the first day of the year is the wkst day (e.g. wkst is Monday and Jan 1st is a Monday)
		// n means there is n days before the first wkst day of the year.
		// if n >= 4, this is the first day of the year (even though it started the year before)
		$first_wkst = (7 - $masks['weekday_of_1st_yearday'] + $this->wkst) % 7;
		if( $first_wkst >= 4 ) {
			$first_wkst_offset = 0;
			// Number of days in the year, plus the days we got from last year.
			$nb_days = $masks['year_len'] + $masks['weekday_of_1st_yearday'] - $this->wkst;
			// $nb_days = $masks['year_len'] + pymod($masks['weekday_of_1st_yearday'] - $this->wkst,7);
		}
		else {
			$first_wkst_offset = $first_wkst;
			// Number of days in the year, minus the days we left in last year.
			$nb_days = $masks['year_len'] - $first_wkst;
		}
		$nb_weeks = (int) ($nb_days / 7) + (int) (($nb_days % 7) / 4);

		// alright now we now when the first week starts
		// and the number of weeks of the year
		// so we can generate a map of every yearday that are in the weeks
		// specified in byweekno
		foreach ( $this->byweekno as $n ) {
			if ( $n < 0 ) {
				$n = $n + $nb_weeks + 1;
			}
			if ( $n <= 0 || $n > $nb_weeks ) {
				continue;
			}
			if ( $n > 1 ) {
				$i = $first_wkst_offset + ($n - 1) * 7;
				if ( $first_wkst_offset != $first_wkst ) {
					// if week #1 started the previous year
					// realign the start of the week
					$i = $i - (7 - $first_wkst);
				}
			}
			else {
				$i = $first_wkst_offset;
			}

			// now add 7 days into the resultset, stopping either at 7 or
			// if we reach wkst before (in the case of short first week of year)
			for ( $j = 0; $j < 7; $j++ ) {
				$masks['yearday_is_in_weekno'][$i] = true;
				$i = $i + 1;
				if ( $masks['yearday_to_weekday'][$i] == $this->wkst ) {
					break;
				}
			}
		}

		// if we asked for week #1, it's possible that the week #1 of next year
		// already started this year. Therefore we need to return also the matching
		// days of next year.
		if ( in_array(1, $this->byweekno) ) {
			// Check week number 1 of next year as well
			// TODO: Check -numweeks for next year.
			$i = $first_wkst_offset + $nb_weeks * 7;
			if ( $first_wkst_offset != $first_wkst ) {
				$i = $i - (7 - $first_wkst);
			}
			if ( $i < $masks['year_len'] ) {
				// If week starts in next year, we don't care about it.
				for ( $j = 0; $j < 7; $j++ ) {
					$masks['yearday_is_in_weekno'][$i] = true;
					$i += 1;
					if ( $masks['yearday_to_weekday'][$i] == $this->wkst ) {
						break;
					}
				}
			}
		}

		if ( $first_wkst_offset ) {
			// Check last week number of last year as well.
			// If first_wkst_offset is 0, either the year started on week start,
			// or week number 1 got days from last year, so there are no
			// days from last year's last week number in this year.
			if ( ! in_array(-1, $this->byweekno) ) {
				$weekday_of_1st_yearday = date('N', mktime(0,0,0,1,1,$year-1));
				$first_wkst_offset_last_year = (7 - $weekday_of_1st_yearday + $this->wkst) % 7;
				$last_year_len = 365 + is_leap_year($year - 1);
				if ( $first_wkst_offset_last_year >= 4) {
					$first_wkst_offset_last_year = 0;
					$nb_weeks_last_year = 52 + (int) ((($last_year_len + ($weekday_of_1st_yearday - $this->wkst) % 7) % 7) / 4);
				}
				else {
					$nb_weeks_last_year = 52 + (int) ((($masks['year_len'] - $first_wkst_offset) % 7) /4);
				}
			}
			else {
				$nb_weeks_last_year = -1;
			}

			if ( in_array($nb_weeks_last_year, $this->byweekno) ) {
				for ( $i = 0; $i < $first_wkst_offset; $i++ ) {
					$masks['yearday_is_in_weekno'][$i] = true;
				}
			}
		}
	}


	/**
	 * This builds an array of every time of the day that matches the BYXXX time
	 * criteria. It will only process $this->frequency at one time. So:
	 * - for HOURLY frequencies it builds the minutes and second of the given hour
	 * - for MINUTELY frequencies it builds the seconds of the given minute
	 * - for SECONDLY frequencies, it returns an array with one element
	 * 
	 * This method is called everytime an increment of at least one hour is made.
	 */
	protected function getTimeSet($hour, $minute, $second)
	{
		// echo "getTimeSet($hour,$minute,$second)\n";
		switch ( $this->freq ) {
			case self::HOURLY:
				$set = array();
				foreach ( $this->byminute as $minute ) {
					foreach ( $this->bysecond as $second ) {
						// should we use another type?
						$set[] = array($hour, $minute, $second);
					}
				}
				// sort ?
				return $set;
			case self::MINUTELY:
				$set = array();
				foreach ( $this->bysecond as $second ) {
					// should we use another type?
					$set[] = array($hour, $minute, $second);
				}
				// sort ?
				return $set;
			case self::SECONDLY:
				return array(array($hour, $minute, $second));
			default:
				throw new \LogicException('getTimeSet called with an invalid frequency');
		}
	}

	/**
	 * This is the main method, where all of the magic happens.
	 *
	 * This method is a generator that works for PHP 5.3/5.4 (using static variables)
	 *
	 * The main idea is : a brute force made fast by not relying on date() functions
	 * 
	 * There is one big loop that examines every interval of the given frequency
	 * (so every day, every week, every month or every year), constructs an
	 * array of all the yeardays of the interval (for daily frequencies, the array
	 * only has one element, for weekly 7, and so on), and then filters out any
	 * day that do no match BYXXX elements.
	 *
	 * The algorithm does not try to be "smart" in calculating the increment of
	 * the loop. That is, for a rule like "every day in January for 10 years"
	 * the algorithm will loop through every day of the year, each year, generating
	 * some 3650 iterations (+ some to account for the leap years).
	 * This is a bit counter-intuitive, as it is obvious that the loop could skip
	 * all the days in February till December since they are never going to match.
	 *
	 * Fortunately, this approach is still super fast because it doesn't rely
	 * on date() or DateTime functions, and instead does all the date operations
	 * manually, either arithmetically or using arrays as converters.
	 *
	 * Another quirk of this approach is that because the granularity is by day,
	 * higher frequencies (hourly, minutely and secondly) have to have
	 * their own special loops within the main loop, making the all thing quite
	 * convoluted.
	 * Moreover, at such frequencies, the brute-force approach starts to really
	 * suck. For example, a rule like
	 * "Every minute, every Jan 1st between 10:00 and 10:59, for 10 years" 
	 * requires a tremendous amount of useless iterations to jump from Jan 1st 10:59
	 * at year 1 to Jan 1st 10.00 at year 2.
	 *
	 * In order to make a "smart jump", we would have to have a way to determine
	 * the gap between the next occurence arithmetically. I think that would require
	 * to analyze each "BYXXX" rule part that "Limit" the set (see the RFC page 43)
	 * at the given frequency. For example, a YEARLY frequency doesn't need "smart
	 * jump" at all; MONTHLY and WEEKLY frequencies only need to check BYMONTH;
	 * DAILY frequency needs to check BYMONTH, BYMONTHDAY and BYDAY, and so on.
	 * The check probably has to be done in reverse order, e.g. for DAILY frequencies
	 * attempt to jump to the next weekday (BYDAY) or next monthday (BYMONTHDAY)
	 * (I don't know yet which one first), and then if that results in a change of
	 * month, attempt to jump to the next BYMONTH, and so on.
	 */
	protected function iterate($reset = false)
	{
		// these are the static variables, i.e. the variables that persists
		// at every call of the method (to emulate a generator)
		static $year = null, $month = null, $day = null;
		static $hour = null, $minute = null, $second = null;
		static $dayset = null, $masks = null, $timeset = null;
		static $total = 0;

		if ( $reset ) {
			$year = $month = $day = null;
			$hour = $minute = $second = null;
			$dayset = $masks = $timeset = null;
			$total = 0;
		}

		// stop once $total has reached COUNT
		if ( $this->count && $total >= $this->count ) {
			return null;
		}

		if ( $year == null ) {
			if ( $this->freq === self::WEEKLY ) {
				// we align the start date to the WKST, so we can then
				// simply loop by adding +7 days. The Python lib does some
				// calculation magic at the end of the loop (when incrementing)
				// to realign on first pass.
				$tmp = clone $this->dtstart;
				$tmp = $tmp->modify('-'.pymod($this->dtstart->format('N') - $this->wkst,7).'days');
				list($year,$month,$day,$hour,$minute,$second) = explode(' ',$tmp->format('Y n j G i s'));
				unset($tmp);
			}
			else {
				list($year,$month,$day,$hour,$minute,$second) = explode(' ',$this->dtstart->format('Y n j G i s'));
			}
			// remove leading zeros
			$minute = (int) $minute;
			$second = (int) $second;
		}

		// we initialize the timeset
		if ( $timeset == null ) {
			if ( $this->freq < self::HOURLY ) {
				// daily, weekly, monthly or yearly
				// we don't need to calculate a new timeset
				$timeset = $this->timeset;
			}
			else {
				// initialize empty if it's not going to occurs on the first iteration
				if (
					($this->freq >= self::HOURLY && $this->byhour && ! in_array($hour, $this->byhour))
					|| ($this->freq >= self::MINUTELY && $this->byminute && ! in_array($minute, $this->byminute))
					|| ($this->freq >= self::SECONDLY && $this->bysecond && ! in_array($second, $this->bysecond))
				) {
					$timeset = array();
				}
				else {
					$timeset = $this->getTimeSet($hour, $minute, $second);
				}
			}
		}

		// while (true) {
		$max_cycles = self::$REPEAT_CYCLES[$this->freq <= self::DAILY ? $this->freq : self::DAILY];
		for ( $i = 0; $i < $max_cycles; $i++ ) {
			// 1. get an array of all days in the next interval (day, month, week, etc.)
			// we filter out from this array all days that do not match the BYXXX conditions
			// to speed things up, we use days of the year (day numbers) instead of date
			if ( $dayset === null ) {
				// rebuild the various masks and converters
				// these arrays will allow fast date operations
				// without relying on date() methods
				if ( empty($masks) || $masks['year'] != $year || $masks['month'] != $month ) {
					$masks = array('year' => '','month'=>'');
					// only if year has changed
					if ( $masks['year'] != $year ) {
						$masks['leap_year'] = is_leap_year($year);
						$masks['year_len'] = 365 + (int) $masks['leap_year'];
						$masks['next_year_len'] = 365 + is_leap_year($year + 1);
						$masks['weekday_of_1st_yearday'] = date('N', mktime(0,0,0,1,1,$year));
						$masks['yearday_to_weekday'] = array_slice(self::$WEEKDAY_MASK, $masks['weekday_of_1st_yearday']-1);
						if ( $masks['leap_year'] ) {
							$masks['yearday_to_month'] = self::$MONTH_MASK_366;
							$masks['yearday_to_monthday'] = self::$MONTHDAY_MASK_366;
							$masks['yearday_to_monthday_negative'] = self::$NEGATIVE_MONTHDAY_MASK_366;
							$masks['last_day_of_month'] = self::$LAST_DAY_OF_MONTH_366;
						}
						else {
							$masks['yearday_to_month'] = self::$MONTH_MASK;
							$masks['yearday_to_monthday'] = self::$MONTHDAY_MASK;
							$masks['yearday_to_monthday_negative'] = self::$NEGATIVE_MONTHDAY_MASK;
							$masks['last_day_of_month'] = self::$LAST_DAY_OF_MONTH;
						}
						if ( $this->byweekno ) {
							$this->buildWeeknoMask($year, $month, $day, $masks);
						}
					}
					// everytime month or year changes
					if ( $this->byweekday_nth ) {
						$this->buildNthWeekdayMask($year, $month, $day, $masks);
					}
					$masks['year'] = $year;
					$masks['month'] = $month;
				}

				// calculate the current set
				$dayset = $this->getDaySet($year, $month, $day, $masks);
// echo"\tWorking with $year-$month-$day set=".json_encode($dayset)."\n";
// print_r(json_encode($masks));
// fgets(STDIN);
				$filtered_set = array();

				foreach ( $dayset as $yearday ) {
					if ( $this->bymonth && ! in_array($masks['yearday_to_month'][$yearday], $this->bymonth) ) {
						continue;
					}

					if ( $this->byweekno && ! isset($masks['yearday_is_in_weekno'][$yearday]) ) {
						continue;
					}

					if ( $this->byyearday ) {
						if ( $yearday < $masks['year_len'] ) {
							if ( ! in_array($yearday + 1, $this->byyearday) && ! in_array(- $masks['year_len'] + $yearday,$this->byyearday) ) {
								continue;
							}
						}
						else { // if ( ($yearday >= $masks['year_len']
							if ( ! in_array($yearday + 1 - $masks['year_len'], $this->byyearday) && ! in_array(- $masks['next_year_len'] + $yearday - $mask['year_len'], $this->byyearday) ) {
								continue;
							}
						}
					}

					if ( ($this->bymonthday || $this->bymonthday_negative)
						&& ! in_array($masks['yearday_to_monthday'][$yearday], $this->bymonthday)
						&& ! in_array($masks['yearday_to_monthday_negative'][$yearday], $this->bymonthday_negative) ) {
						continue;
					}

					if ( $this->byweekday && ! in_array($masks['yearday_to_weekday'][$yearday], $this->byweekday) ) {
						continue;
					}

					if ( $this->byweekday_nth && ! isset($masks['yearday_is_nth_weekday'][$yearday]) ) {
						continue;
					}

					$filtered_set[] = $yearday;
				}
// echo "\tFiltered set (before BYSETPOS)=".json_encode($filtered_set)."\n";

				$dayset = $filtered_set;

				// if BYSETPOS is set, we need to expand the timeset to filter by pos
				// so we make a special loop to return while generating
				if ( $this->bysetpos && $timeset ) {
					$filtered_set = array();
					foreach ( $this->bysetpos as $pos ) {
						// echo "pos = $pos => ";
						$n = count($timeset);
						if ( $pos < 0 ) {
							$pos = $n * count($dayset) + $pos;
						}
						else {
							$pos = $pos - 1;
						}
// echo "$pos => ";
						$div = (int) ($pos / $n); // daypos
						$mod = $pos % $n; // timepos
	// echo "array index $div / $mod\n";
	// echo "div = $div, mod = $mod\n";
	// echo "dayset[$div] = ",$dayset[$div]," timeset[$mod] = ",json_encode($timeset[$mod]),"\n";
	// fgets(STDIN);

						if ( isset($dayset[$div]) && isset($timeset[$mod]) ) {
							$yearday = $dayset[$div];
							$time = $timeset[$mod];
							// used as array key to ensure uniqueness
							$tmp = $year.':'.$yearday.':'.$time[0].':'.$time[1].':'.$time[2];
							if ( ! isset($filtered_set[$tmp]) ) {
								$occurrence = \DateTime::createFromFormat(
									'Y z',
									"$year $yearday"
								);
								$occurrence->setTime($time[0], $time[1], $time[2]);
								$filtered_set[$tmp] = $occurrence;
							}
						}
					}
					sort($filtered_set);
					$dayset = $filtered_set;
				}
// echo "\tFiltered set (after BYSETPOS)=".json_encode($filtered_set)."\n";
			}

			// 2. loop, generate a valid date, and return the result (fake "yield")
			// at the same time, we check the end condition and return null if
			// we need to stop
			if ( $this->bysetpos && $timeset ) {
				while ( ($occurrence = current($dayset)) !== false ) {

					// consider end conditions
					if ( $this->until && $occurrence > $this->until ) {
						// $this->length = $total (?)
						return null;
					}

					next($dayset);
					if ( $occurrence >= $this->dtstart ) { // ignore occurrences before DTSTART
						$total += 1;
						return $occurrence; // yield
					}
				}
			}
			else {
				// normal loop, without BYSETPOS
				while ( ($yearday = current($dayset)) !== false ) {
					$occurrence = \DateTime::createFromFormat('Y z', "$year $yearday");

					while ( ($time = current($timeset)) !== false ) {
						$occurrence->setTime($time[0], $time[1], $time[2]);
						// consider end conditions
						if ( $this->until && $occurrence > $this->until ) {
							// $this->length = $total (?)
							return null;
						}

						next($timeset);
						if ( $occurrence >= $this->dtstart ) { // ignore occurrences before DTSTART
							$total += 1;
							return $occurrence; // yield
						}
					}
					reset($timeset);
					next($dayset);
				}
			}

			// 3. we reset the loop to the next interval
			$days_increment = 0;
			switch ( $this->freq ) {
				case self::YEARLY:
					// we do not care about $month or $day not existing,
					// they are not used in yearly frequency
					$year = $year + $this->interval;
					break;
				case self::MONTHLY:
					// we do not care about the day of the month not existing
					// it is not used in monthly frequency
					$month = $month + $this->interval;
					if ( $month > 12 ) {
						$div = (int) ($month / 12);
						$mod = $month % 12;
						$month = $mod;
						$year = $year + $div;
						if ( $month == 0 ) {
							$month = 12;
							$year = $year - 1;
						}
					}
					break;
				case self::WEEKLY:
					$days_increment = $this->interval*7;
					break;
				case self::DAILY:
					$days_increment = $this->interval;
					break;

				// For the time frequencies, things are a little bit different.
				// We could just add "$this->interval" hours, minutes or seconds
				// to the current time, and go through the main loop again,
				// but since the frequencies are so high and needs to much iteration
				// it's actually a bit faster to have custom loops and only
				// call the DateTime method at the very end.

				case self::HOURLY:
					if ( empty($dayset) ) {
						// an empty set means that this day has been filtered out
						// by one of the BYXXX rule. So there is no need to
						// examine it any further, we know nothing is going to
						// occur anyway.
						// so we jump to one iteration right before next day
						$hour += ((int) ((23 - $hour) / $this->interval)) * $this->interval;
					}

					$found = false;
					for ( $j = 0; $j < self::$REPEAT_CYCLES[self::HOURLY]; $j++ ) {
						$hour += $this->interval;
						$div = (int) ($hour / 24);
						$mod = $hour % 24;
						if ( $div ) {
							$hour = $mod;
							$days_increment += $div;
						}
						if ( ! $this->byhour || in_array($hour, $this->byhour)) {
							$found = true;
							break;
						}
					}

					if ( ! $found ) {
						return null; // stop the iterator
					}

					$timeset = $this->getTimeSet($hour, $minute, $second);
					break;
				case self::MINUTELY:
					if ( empty($dayset) ) {
						$minute += ((int) ((1439 - ($hour*60+$minute)) / $this->interval)) * $this->interval;
					}

					$found = false;
					for ( $j = 0; $j < self::$REPEAT_CYCLES[self::MINUTELY]; $j++ ) {
						$minute += $this->interval;
						$div = (int) ($minute / 60);
						$mod = $minute % 60;
						if ( $div ) {
							$minute = $mod;
							$hour += $div;
							$div = (int) ($hour / 24);
							$mod = $hour % 24;
							if ( $div ) {
								$hour = $mod;
								$days_increment += $div;
							}
						}
						if ( (! $this->byhour || in_array($hour, $this->byhour)) &&
						(! $this->byminute || in_array($minute, $this->byminute)) ) {
							$found = true;
							break;
						}
					}

					if ( ! $found ) {
						return null; // stop the iterator
					}

					$timeset = $this->getTimeSet($hour, $minute, $second);
					break;
				case self::SECONDLY:
					if ( empty($dayset) ) {
						$second += ((int) ((86399 - ($hour*3600 + $minute*60 + $second)) / $this->interval)) * $this->interval;
					}

					$found = false;
					for ( $j = 0; $j < self::$REPEAT_CYCLES[self::SECONDLY]; $j++ ) {
						$second += $this->interval;
						$div = (int) ($second / 60);
						$mod = $second % 60;
						if ( $div ) {
							$second = $mod;
							$minute += $div;
							$div = (int) ($minute / 60);
							$mod = $minute % 60;
							if ( $div ) {
								$minute = $mod;
								$hour += $div;
								$div = (int) ($hour / 24);
								$mod = $hour % 24;
								if ( $div ) {
									$hour = $mod;
									$days_increment += $div;
								}
							}
						}
						if ( ( ! $this->byhour || in_array($hour, $this->byhour) )
							&& ( ! $this->byminute || in_array($minute, $this->byminute) ) 
							&& ( ! $this->bysecond || in_array($second, $this->bysecond) ) ) {
							$found = true;
							break;
						}
					}

					if ( ! $found ) {
						return null; // stop the iterator
					}

					$timeset = $this->getTimeSet($hour, $minute, $second);
					break;
			}
			// here we take a little shortcut from the Python version, by using DateTime
			if ( $days_increment ) {
				list($year,$month,$day) = explode('-',date_create("$year-$month-$day")->modify("+ $days_increment days")->format('Y-n-j'));
			}
			$dayset = null; // reset the loop
		}
		return null; // stop the iterator
	}

// constants
// Every mask is 7 days longer to handle cross-year weekly periods.

	public static $MONTH_MASK = array(
		1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,
		2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,
		3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,
		4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,
		5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,
		6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,
		7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,
		8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,
		9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,
		10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,
		11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,
		12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,
		1,1,1,1,1,1,1
	);

	public static $MONTH_MASK_366 = array(
		1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,
		2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,
		3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,3,
		4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4,
		5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,5,
		6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,6,
		7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,7,
		8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,8,
		9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,9,
		10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,10,
		11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,11,
		12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,12,
		1,1,1,1,1,1,1
	);

	public static $MONTHDAY_MASK = array(
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,
		1,2,3,4,5,6,7
	);

	public static $MONTHDAY_MASK_366 = array(
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,
		1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,
		1,2,3,4,5,6,7
	);

	public static $NEGATIVE_MONTHDAY_MASK = array(
		-31,-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-31,-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-31,-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-31,-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-31,-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-31,-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-31,-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-31,-30,-29,-28,-27,-26,-25
	);

	public static $NEGATIVE_MONTHDAY_MASK_366 = array(
		-31,-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-31,-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-31,-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-31,-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-31,-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-31,-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-31,-30,-29,-28,-27,-26,-25,-24,-23,-22,-21,-20,-19,-18,-17,-16,-15,-14,-13,-12,-11,-10,-9,-8,-7,-6,-5,-4,-3,-2,-1,
		-31,-30,-29,-28,-27,-26,-25
	);

	public static $WEEKDAY_MASK = array(
		1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,
		1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,
		1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,
		1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,
		1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,
		1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,
		1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,
		1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,
		1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,
		1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,
		1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7,1,2,3,4,5,6,7
	);

	public static $LAST_DAY_OF_MONTH_366 = array(
		0, 31, 60, 91, 121, 152, 182, 213, 244, 274, 305, 335, 366
	);

	public static $LAST_DAY_OF_MONTH = array(
		0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334, 365
	);

	/**
	 * Maximum number of cycles after which a calendar repeats itself. This
	 * is used to detect infinite loop: if no occurrence has been found 
	 * after this numbers of cycles, we can abort.
	 *
	 * The Gregorian calendar cycle repeat completely every 400 years
	 * (146,097 days or 20,871 weeks).
	 * A smaller cycle would be 28 years (1,461 weeks), but it only works
	 * if there is no dropped leap year in between.
	 * 2100 will be a dropped leap year, but I'm going to assume it's not
	 * going to be a problem anytime soon, so at the moment I use the 28 years
	 * cycle.
	 */
	public static $REPEAT_CYCLES = array(
		// self::YEARLY => 400,
		// self::MONTHLY => 4800,
		// self::WEEKLY => 20871,
		// self::DAILY =>  146097, // that's a lot of cycles, it takes a few seconds to detect infinite loop
		self::YEARLY => 28,
		self::MONTHLY => 336,
		self::WEEKLY => 1461,
		self::DAILY => 10227,

		self::HOURLY => 24,
		self::MINUTELY => 1440,
		self::SECONDLY => 86400 // that's a lot of cycles too
	);
}