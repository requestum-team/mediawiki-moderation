<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018-2020 Edward Chernenko.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
*/

/**
 * @file
 * Displays mod_timestamp on Special:Moderation.
 */

class ModerationFormatTimestamp {

	/**
	 * Returns human-readable version of $timestamp.
	 * @param mixed $timestamp
	 * @param IContextSource $context
	 * @return string
	 */
	public static function format( $timestamp, IContextSource $context ) {
		$lang = $context->getLanguage();
		$user = $context->getUser();

		if ( self::isToday( $timestamp, $lang ) ) {
			/* Only time */
			return $lang->userTime( $timestamp, $user );
		}

		/* Time and date */
		return $lang->userTimeAndDate( $timestamp, $user );
	}

	/**
	 * Returns true if $timestamp is today, false otherwise.
	 * @param string $timestamp
	 * @param Language $lang
	 * @return bool
	 */
	protected static function isToday( $timestamp, Language $lang ) {
		static $today = '',
			$skippedToday = false;

		if ( $skippedToday ) {
			/* Optimization: results are sorted by timestamp (DESC),
				so if we found even one timestamp with isToday=false,
				then isToday=false for all following timestamps. */
			return false;
		}

		// Respect the timezone selected by current user.
		$timestamp = (string)$lang->userAdjust( wfTimestamp( TS_MW, $timestamp ) );

		if ( !$today ) {
			$today = substr( (string)$lang->userAdjust( wfTimestampNow() ), 0, 8 );
		}

		$isToday = ( substr( $timestamp, 0, 8 ) == $today );
		if ( !$isToday ) {
			$skippedToday = true; /* The following timestamps are even earlier, no need to check them */
		}

		return $isToday;
	}
}
