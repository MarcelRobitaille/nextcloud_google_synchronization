<?php

/**
 * Nextcloud - google
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Google\Service;

use DateTime;
use DateTimeZone;
use Ds\Set;
use Exception;
use Generator;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Google\AppInfo\Application;
use OCA\Google\BackgroundJob\ImportCalendarJob;
use OCP\BackgroundJob\IJobList;
use OCP\Config\IUserConfig;
use OCP\IL10N;
use Ortic\ColorConverter\Color;
use Ortic\ColorConverter\Colors\Named;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\PropPatch;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Throwable;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Service to make requests to Google v3 (JSON) API
 *
 * @phpstan-type Event array{id: string, iCalUID: string, start?: array{date?: string, dateTime?: string, timeZone?: string}, end?: array{date?: string, dateTime?: string, timeZone?: string}, originalStartTime?: array{date?: string, dateTime?: string, timeZone?: string}, recurringEventId?: string, colorId?: string, summary?: string, visibility?: string, sequence?: string, location?: string, description?: string, status?: string, created?: string, updated?: string, reminders?: array{useDefault?: bool, overrides?: list{array{minutes?: string, hours?: string, days?: string, weeks?: string}}}, recurrence?: list<string>}
 */
class GoogleCalendarAPIService {
	private DateTimeZone $utcTimezone;

	public function __construct(
		protected string $appName,
		private LoggerInterface $logger,
		private IL10N $l10n,
		private CalDavBackend $caldavBackend,
		private IJobList $jobList,
		private GoogleAPIService $googleApiService,
		private IUserConfig $userConfig,
	) {
		$this->utcTimezone = new DateTimeZone('-0000');
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function getCalendarList(string $userId): array {
		$result = $this->googleApiService->request($userId, 'calendar/v3/users/me/calendarList');
		if (isset($result['error']) || !isset($result['items'])) {
			return $result;
		}
		return $result['items'];
	}

	/**
	 * @param string $userId
	 * @param string $uri
	 * @return ?int the calendar ID
	 */
	private function calendarExists(string $userId, string $uri): ?int {
		$res = $this->caldavBackend->getCalendarByUri('principals/users/' . $userId, $uri);
		return is_null($res) || $this->isInTrash($res)
			? null
			: $res['id'];
	}

	/**
	 * Whether a calendar is in the trash, i.e. was deleted from the UI.
	 *
	 * Deleting a calendar in Nextcloud only stamps deleted_at, and none of
	 * getCalendarsForUser(), getCalendarByUri() or getCalendarById() filter it out
	 * on any supported version (32 to 35): they select the column, because it is in
	 * propertyMap, but never look at it. Such a calendar is gone from the UI and
	 * cannot be restored from it either, so importing into it would make the sync
	 * look like it silently stopped working.
	 *
	 * @param array<string, mixed> $calendar as returned by the CalDavBackend getters
	 */
	private function isInTrash(array $calendar): bool {
		return ($calendar['{http://owncloud.org/ns}deleted-at'] ?? null) !== null;
	}

	/**
	 * Display name given to an imported Google calendar.
	 *
	 * The translated suffix is purely cosmetic: it must never take part in the
	 * identity of the calendar.
	 */
	private function getImportedCalendarName(string $calName): string {
		return trim($calName) . ' (' . $this->l10n->t('Google Calendar import') . ')';
	}

	/**
	 * CalDAV URI of an imported Google calendar.
	 *
	 * Derived from the Google calendar id because, unlike the display name, it is
	 * unique per calendar and survives renaming the calendar in Google. It is
	 * deliberately independent of the language of the Nextcloud instance.
	 *
	 * Replaces four earlier naming schemes, see isLegacyImportOf() for those and
	 * for the calendars they left behind.
	 */
	private function getStableCalendarUri(string $calId): string {
		return 'google-import-' . substr(hash('sha256', $calId), 0, 32);
	}

	/**
	 * Whether a calendar was created by an earlier version of the app as the
	 * import of the Google calendar of the given name.
	 *
	 * The app has named that calendar four different ways over the years, and one
	 * created by any of them still has to be recognised here, or its events would
	 * be orphaned and the Google calendar imported all over again:
	 *
	 *   uri                    displayname      since
	 *   ---------------------  ---------------  -----------------------------------
	 *   trim($calName) . '…'   same as the uri  3c7c084, 68a6806
	 *   urlencode(name)        same as the uri  db4fad6
	 *   urlencode(name)        name             3a1ed54, 88670a4, 0f158eb
	 *   google-import-<hash>   name             f0b6e04
	 *
	 * 3c7c084 could also append a counter to break ties, which is what the
	 * (?:-\d+)? below matches. 3a1ed54 and 88670a4 left the stored uri alone but
	 * moved the key the calendar was looked up by from one form to the other,
	 * which is how the duplicates of #46 came about: every release adopted a
	 * different one of the calendars an earlier release had created. 163a1bd is
	 * deliberately not in the list despite its subject, it URL encoded the Google
	 * calendar ids rather than the name of the Nextcloud calendar.
	 *
	 * Re-derive the table with:
	 *   git log -p -- lib/Service/GoogleCalendarAPIService.php
	 *     | grep -E "^\+.*(newCalName|newCalUri|createCalendar\('principals"
	 *
	 * The translated suffix is never compared literally, only the name of the
	 * Google calendar, the parenthesised suffix every name ends with, and the
	 * counter that some versions appended to it to break ties.
	 *
	 * @param array{id: int, uri?: string, principaluri?: string, '{DAV:}displayname'?: string} $calendar
	 */
	private function isLegacyImportOf(array $calendar, string $calName): bool {
		$uri = $calendar['uri'] ?? '';
		// Some listings do not carry the display name, the URI is then all there is
		$name = ($calendar['{DAV:}displayname'] ?? '') ?: $uri;
		if ($name === '') {
			return false;
		}
		// All the schemes they went through are accepted and nothing else: a calendar
		// whose URI and display name disagree was not named by this app, and taking
		// it over means overwriting it
		if (!in_array($uri, [$name, urlencode($name), urldecode($name)], true)) {
			return false;
		}
		// No /u on purpose: names may hold invalid UTF-8, which would make preg_match() fail
		$pattern = '/^' . preg_quote(trim($calName), '/') . '(?:-\d+)? \(.*\)$/D';
		return preg_match($pattern, $name) === 1 || preg_match($pattern, urldecode($name)) === 1;
	}

	/**
	 * Find the calendars earlier versions of the app created for a Google
	 * calendar, which are duplicates of each other whenever the app was run with
	 * more than one language, or more than one naming scheme, over time.
	 *
	 * @return int[] the ids of the matching calendars, in ascending order
	 */
	private function findLegacyImportedCalendars(string $principalUri, string $calName): array {
		if (trim($calName) === '') {
			// Without a name there is nothing to recognise a calendar by
			return [];
		}
		$ids = [];
		foreach ($this->caldavBackend->getCalendarsForUser($principalUri) as $calendar) {
			/** @var array{id: int, uri?: string, principaluri?: string, '{DAV:}displayname'?: string} $calendar */
			if (($calendar['principaluri'] ?? null) !== $principalUri
				|| $this->isInTrash($calendar)
				|| !$this->isLegacyImportOf($calendar, $calName)) {
				continue;
			}
			$ids[] = (int)$calendar['id'];
		}
		return $ids;
	}

	/**
	 * Get the Nextcloud calendar backing a Google calendar, creating it if needed.
	 *
	 * Earlier versions named the calendar after the Google calendar and the
	 * translated suffix of that name, which made the very same Google calendar
	 * get imported more than once. Those calendars are still recognised here, so
	 * that existing imports keep being updated instead of being duplicated again;
	 * see isLegacyImportOf() for the naming schemes involved.
	 *
	 * @param string $userId
	 * @param string $calId the Google calendar id
	 * @param string $calName the Google calendar name, used for the display name
	 * @param ?string $color
	 * @return array{int, bool} the calendar id, and whether it was just created
	 */
	private function resolveImportedCalendar(string $userId, string $calId, string $calName, ?string $color): array {
		$ncCalId = $this->calendarExists($userId, $this->getStableCalendarUri($calId));
		if ($ncCalId !== null) {
			return [$ncCalId, false];
		}

		$principalUri = 'principals/users/' . $userId;
		$displayName = $this->getImportedCalendarName($calName);

		// Calendar created by an earlier version of the app, in any of the naming
		// schemes and any of the languages it went through.
		$legacyIds = $this->findLegacyImportedCalendars($principalUri, $calName);
		if ($legacyIds !== []) {
			// keep the oldest one, the others are duplicates of the same calendar
			$kept = min($legacyIds);
			$this->logger->debug(
				"Reusing Nextcloud calendar $kept previously imported from Google calendar $calId",
				['app' => Application::APP_ID]
			);
			if (count($legacyIds) > 1) {
				$duplicates = array_values(array_diff($legacyIds, [$kept]));
				// Logged on every run: an adopted calendar keeps its legacy URI, so
				// the scan is repeated for as long as the duplicates are left in place
				$this->logger->debug(
					'Nextcloud calendars ' . implode(', ', $legacyIds)
						. " all import the Google calendar $calId."
						. "Keeping $kept and ignoring " . implode(', ', $duplicates)
						. '. The unused ones can be deleted manually.',
					['app' => Application::APP_ID]
				);
			}
			return [$kept, false];
		}

		// A Google calendar without a name cannot be recognised by one, so fall
		// back to the exact URIs earlier versions would have used for it.
		foreach ([urlencode($displayName), $displayName] as $legacyUri) {
			$legacyId = $this->calendarExists($userId, $legacyUri);
			if ($legacyId !== null) {
				$this->logger->debug(
					"Reusing Nextcloud calendar $legacyId previously imported from Google calendar $calId",
					['app' => Application::APP_ID]
				);
				return [$legacyId, false];
			}
		}

		$params = [];
		if ($color) {
			$params['{http://apple.com/ns/ical/}calendar-color'] = $color;
		}
		$params['{DAV:}displayname'] = $displayName;
		$newCalId = $this->caldavBackend->createCalendar(
			$principalUri,
			$this->getStableCalendarUri($calId),
			$params,
		);
		// Ensures the right name is given to the calendar
		$proppatch = new PropPatch(['{DAV:}displayname' => $displayName]);
		$this->caldavBackend->updateCalendar($newCalId, $proppatch);

		return [$newCalId, true];
	}

	/**
	 * @param array{date?: string, dateTime?: string, timeZone?: string} $obj The datetime object to map.
	 * @return string The date time mapped to the best representation from the available data.
	 */
	private function mapTime(array $obj): string {
		if (isset($obj['dateTime'])) {
			$dateTime = new DateTime($obj['dateTime']);

			if (isset($obj['timeZone'])) {
				$timezone = $obj['timeZone'];
				$dateTime->setTimezone(new DateTimeZone($timezone));
				return "TZID=$timezone:" . $dateTime->format('Ymd\THis');
			} else {
				$dateTime->setTimezone($this->utcTimezone);
				return 'VALUE=DATE-TIME:' . $dateTime->format('Ymd\THis\Z');
			}
		} elseif (isset($obj['date'])) {
			// whole days
			$date = new DateTime($obj['date']);
			return 'VALUE=DATE:' . $date->format('Ymd');
		} else {
			// skip entries without any date
			return '';
		}
	}

	/**
	 * @param Event $e The event from which to generate the data.
	 * @param array<Event> $exceptions The events that represent recurring exceptions.
	 * @param int $ncCalId The id of the event's calendar.
	 * @param array $eventColors The event colors mapping.
	 */
	private function generateEventData(array $e, array $exceptions, int $ncCalId, array $eventColors): string {
		$eventData = 'BEGIN:VEVENT' . "\n";

		$eventData .= 'UID:' . strval($ncCalId) . '-' . $e['iCalUID'] . "\n";
		if (isset($e['colorId'], $eventColors[$e['colorId']], $eventColors[$e['colorId']]['background'])) {
			$closestCssColor = $this->getClosestCssColor($eventColors[$e['colorId']]['background']);
			$eventData .= 'COLOR:' . $closestCssColor . "\n";
		}
		$eventData .= isset($e['summary'])
			? ('SUMMARY:' . mb_strcut(str_replace("\n", '\n', $e['summary']), 0, 250) . "\n")
			: (($e['visibility'] ?? '') === 'private'
				? ('SUMMARY:' . $this->l10n->t('Private event') . "\n")
				: '');
		$eventData .= isset($e['sequence']) ? ('SEQUENCE:' . $e['sequence'] . "\n") : '';
		$eventData .= isset($e['location'])
			? ('LOCATION:' . mb_strcut(str_replace("\n", '\n', $e['location']), 0, 250) . "\n")
			: '';
		$eventData .= isset($e['description'])
			? ('DESCRIPTION:' . mb_strcut(str_replace("\n", '\n', $e['description']), 0, 250) . "\n")
			: '';
		$eventData .= isset($e['status']) ? ('STATUS:' . strtoupper(str_replace("\n", '\n', $e['status'])) . "\n") : '';

		if (isset($e['created'])) {
			$created = new DateTime($e['created']);
			$created->setTimezone($this->utcTimezone);
			$eventData .= 'CREATED:' . $created->format('Ymd\THis\Z') . "\n";
		}

		if (isset($e['updated'])) {
			$updated = new DateTime($e['updated']);
			$updated->setTimezone($this->utcTimezone);
			$eventData .= 'LAST-MODIFIED:' . $updated->format('Ymd\THis\Z') . "\n";
		}

		if (isset($e['reminders'], $e['reminders']['useDefault']) && $e['reminders']['useDefault']) {
			// 15 min before, default alarm
			$eventData .= 'BEGIN:VALARM' . "\n"
				. 'ACTION:DISPLAY' . "\n"
				. 'TRIGGER;RELATED=START:-PT15M' . "\n"
				. 'END:VALARM' . "\n";
		}
		if (isset($e['reminders'], $e['reminders']['overrides'])) {
			foreach ($e['reminders']['overrides'] as $o) {
				$nbMin = 0;
				if (isset($o['minutes'])) {
					$nbMin += (int)$o['minutes'];
				}
				if (isset($o['hours'])) {
					$nbMin += ((int)$o['hours']) * 60;
				}
				if (isset($o['days'])) {
					$nbMin += ((int)$o['days']) * 60 * 24;
				}
				if (isset($o['weeks'])) {
					$nbMin += ((int)$o['weeks']) * 60 * 24 * 7;
				}
				$eventData .= 'BEGIN:VALARM' . "\n"
					. 'ACTION:DISPLAY' . "\n"
					. 'TRIGGER;RELATED=START:-PT' . $nbMin . 'M' . "\n"
					. 'END:VALARM' . "\n";
			}
		}

		if (isset($e['recurrence']) && is_array($e['recurrence'])) {
			foreach ($e['recurrence'] as $r) {
				$eventData .= $r . "\n";
			}
		}

		// skip entries without any date
		if (!isset($e['start']) || !isset($e['end'])) {
			return '';
		}

		$start = $this->mapTime($e['start']);
		$end = $this->mapTime($e['end']);

		// skip entries without any date
		if ($start == '' || $end == '') {
			return '';
		}

		$eventData .= "DTSTART;$start\n";
		$eventData .= "DTEND;$end\n";

		if (isset($e['recurringEventId'], $e['originalStartTime'])) {
			$recurrenceId = $this->mapTime($e['originalStartTime']);
			$eventData .= "RECURRENCE-ID;$recurrenceId\n";
		}

		$eventData .= 'CLASS:PUBLIC' . "\n"
			. 'END:VEVENT' . "\n";

		foreach ($exceptions as $candidateException) {
			if (($candidateException['recurringEventId'] == $e['id']) && ($candidateException['id'] != $e['id'])) {
				$eventData .= $this->generateEventData($candidateException, $exceptions, $ncCalId, $eventColors);
			}
		}

		return $eventData;
	}

	/**
	 * @param string $hexColor
	 * @return string closest CSS color name
	 */
	private function getClosestCssColor(string $hexColor): string {
		/** @var Color $color */
		$color = Color::fromString($hexColor);
		$rbgColor = [
			'r' => $color->getRed(),
			'g' => $color->getGreen(),
			'b' => $color->getBlue(),
		];
		// init
		$closestColor = 'black';
		$black = Color::fromString(Named::CSS_COLORS['black']);
		$rgbBlack = [
			'r' => $black->getRed(),
			'g' => $black->getGreen(),
			'b' => $black->getBlue(),
		];
		$closestDiff = $this->colorDiff($rbgColor, $rgbBlack);

		foreach (Named::CSS_COLORS as $name => $hex) {
			$c = Color::fromString($hex);
			$rgb = [
				'r' => $c->getRed(),
				'g' => $c->getGreen(),
				'b' => $c->getBlue(),
			];
			$diff = $this->colorDiff($rbgColor, $rgb);
			if ($diff < $closestDiff) {
				$closestDiff = $diff;
				$closestColor = $name;
			}
		}

		return $closestColor;
	}

	/**
	 * @param array{r:int, g:int, b:int} $rgb1 first color
	 * @param array{r:int, g:int, b:int} $rgb2 second color
	 *
	 * @return int the distance between colors
	 */
	private function colorDiff(array $rgb1, array $rgb2): int|float {
		return abs($rgb1['r'] - $rgb2['r']) + abs($rgb1['g'] - $rgb2['g']) + abs($rgb1['b'] - $rgb2['b']);
	}

	/**
	 * Get last modified timestamp from the calendar data of a calendar object
	 *
	 * @param string $calData
	 * @return int|null
	 * @throws Exception
	 */
	private function getEventLastModifiedTimestamp(string $calData): ?int {
		/** @var VCalendar $vCalendar */
		$vCalendar = Reader::read($calData);
		/** @var VEvent $vEvent */
		$vEvent = $vCalendar->{'VEVENT'};
		$iCalEvents = $vEvent->getIterator();
		foreach ($iCalEvents as $event) {
			if (isset($event->{'LAST-MODIFIED'})) {
				$lastMod = $event->{'LAST-MODIFIED'};
				if (is_string($lastMod)) {
					return (new DateTime($lastMod))->getTimestamp();
				} elseif ($lastMod instanceof \Sabre\VObject\Property\ICalendar\DateTime) {
					return $lastMod->getDateTime()->getTimestamp();
				}
			}
		}
		return null;
	}

	/**
	 * get the most recent event update date in a calendar
	 *
	 * @param int $calendarId
	 * @return int
	 */
	private function getCalendarLastEventModificationTimestamp(int $calendarId): int {
		$objects = $this->caldavBackend->getCalendarObjects($calendarId);
		$lastModifieds = array_map(static function (array $object) {
			return $object['lastmodified'] ?? 0;
		}, $objects);
		return max($lastModifieds);
	}

	/**
	 * @param string $userId
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return array{error: string}|array{nbAdded: int, nbUpdated: int, calName: string}
	 */
	public function safeImportCalendar(string $userId, string $calId, string $calName, ?string $color = null): array {
		$startTime = microtime(true);
		$this->logger->debug("Starting calendar import of $calId", ['app' => $this->appName]);

		$lockFile = sys_get_temp_dir()
			. "/nextcloud_google_synchronization_calendar_import_$calId.lock";

		if (file_exists($lockFile)) {
			throw new Exception('Could not acquire lock');
		}

		touch($lockFile);

		try {
			return $this->importCalendar($userId, $calId, $calName, $color);
		} finally {
			$this->logger->debug('Elapsed time is: ' . (microtime(true) - $startTime) . ' seconds', ['app' => $this->appName]);
			try {
				unlink($lockFile);
			} catch (Exception) {
			}
		}
	}

	/**
	 * @param string $userId
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return array{nbAdded: int, nbUpdated: int, calName: string} | array{error: string}
	 */
	public function importCalendar(string $userId, string $calId, string $calName, ?string $color = null): array {
		$newCalName = $this->getImportedCalendarName($calName);
		[$ncCalId, $calendarIsNew] = $this->resolveImportedCalendar($userId, $calId, $calName, $color);

		/** @var Set<string> $unseenURIs */
		$unseenURIs = new Set();
		/** @var array{uri: string} $e */
		foreach ($this->caldavBackend->getCalendarObjects($ncCalId) as $e) {
			$unseenURIs->add($e['uri']);
		}

		// get color list
		$eventColors = [];
		/** @type array{error: string}|array{event: array} $colors */
		$colors = $this->googleApiService->request($userId, 'calendar/v3/colors');
		if (!isset($colors['error']) && isset($colors['event'])) {
			$eventColors = $colors['event'];
		}

		date_default_timezone_set('UTC');
		$allEvents = $this->userConfig->getValueString($userId, Application::APP_ID, 'consider_all_events', '1', lazy: true) === '1';
		$eventsGenerator = $this->getCalendarEvents($userId, $calId, $allEvents);

		// Normal events
		$events = [];
		// Exceptions to recurring events (recurringEventId set).
		$exceptions = [];

		foreach ($eventsGenerator as $e) {
			if (isset($e['recurringEventId'])) {
				array_push($exceptions, $e);
			} else {
				array_push($events, $e);
			}
		}

		$nbAdded = 0;
		$nbUpdated = 0;

		/** @var Event $e */
		foreach ($events as $e) {
			$objectUri = $e['id'];

			// If this event exists in NC, remove it from the set of events to be
			// deleted. Continue processing it, it could have been updated.
			if ($unseenURIs->contains($objectUri)) {
				$unseenURIs->remove($objectUri);
			}

			$existingEvent = null;
			// check if we should update existing events (on existing calendars only :-)
			if (!$calendarIsNew) {
				// check if it already exists and if we should update it
				$existingEvent = $this->caldavBackend->getCalendarObject($ncCalId, $objectUri);
				if ($existingEvent !== null) {
					if (!isset($e['updated'])) {
						continue;
					}
					$remoteEventUpdatedTimestamp = (new DateTime($e['updated']))->getTimestamp();
					$localEventUpdatedTimestamp = $this->getEventLastModifiedTimestamp($existingEvent['calendardata']);
					if ($localEventUpdatedTimestamp !== null && $remoteEventUpdatedTimestamp <= $localEventUpdatedTimestamp) {
						continue;
					}
				}
			}

			$eventData = $this->generateEventData($e, $exceptions, $ncCalId, $eventColors);

			if ($eventData == '') {
				continue;
			}

			$calData = 'BEGIN:VCALENDAR' . "\n"
				. 'VERSION:2.0' . "\n"
				. 'PRODID:NextCloud Calendar' . "\n"
				. $eventData
				. 'END:VCALENDAR';

			if ($existingEvent !== null) {
				try {
					$this->caldavBackend->updateCalendarObject($ncCalId, $objectUri, $calData);
					$nbUpdated++;
				} catch (Exception|Throwable $ex) {
					$this->logger->warning('Error when updating calendar event ' . $ex->getMessage(), ['app' => Application::APP_ID]);
				}
			} else {
				try {
					$this->caldavBackend->createCalendarObject($ncCalId, $objectUri, $calData);
					$nbAdded++;
				} catch (BadRequest $ex) {
					if (strpos($ex->getMessage(), 'uid already exists') !== false) {
						$this->logger->debug('Skip existing event', ['app' => Application::APP_ID]);
					} else {
						$this->logger->warning('Error when creating calendar event "' . '<redacted>' . '" ' . $ex->getMessage(), ['app' => Application::APP_ID]);
					}
				} catch (Exception|Throwable $ex) {
					$this->logger->warning('Error when creating calendar event "' . '<redacted>' . '" ' . $ex->getMessage(), ['app' => Application::APP_ID]);
				}
			}
		}

		// Check for error after exhausting the generator but before deleting unseen items.
		$eventGeneratorReturn = $eventsGenerator->getReturn();
		if (isset($eventGeneratorReturn['error'])) {
			$this->logger->error('Google Calendar API error: ' . $eventGeneratorReturn['error'], ['app' => Application::APP_ID]);
			return [ 'error' => $eventGeneratorReturn['error'] ];
		}

		// Anything still unseen was deleted in Google Calendar
		// Reflect that here
		foreach ($unseenURIs as $uri) {
			$this->caldavBackend->deleteCalendarObject($ncCalId, $uri, $this->caldavBackend::CALENDAR_TYPE_CALENDAR, true);
		}

		return [
			'nbAdded' => $nbAdded,
			'nbUpdated' => $nbUpdated,
			'calName' => $newCalName,
		];
	}

	/**
	 * Delete all the registered calendar sync jobs from the database.
	 */
	public function deleteBackgroundJobs(): void {
		$this->jobList->remove(ImportCalendarJob::class);
	}

	/**
	 * Check if a background job is registered.
	 * @param string $userId The user id of the job.
	 * @param string $calId The calendar id of the job.
	 * @return bool Whether the job with the given parameters is registered.
	 */
	public function isJobRegisteredForCalendar(string $userId, string $calId): bool {
		foreach ($this->jobList->getJobsIterator(ImportCalendarJob::class, null, 0) as $job) {
			$args = $job->getArgument();

			if ($args['user_id'] == $userId && $args['cal_id'] == $calId) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register a calendar to periodically be synced and kept up to date in the
	 * background
	 * @param string $userId
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return void
	 */
	public function registerSyncCalendar(string $userId, string $calId, string $calName, ?string $color = null): void {
		$argument = [
			'user_id' => $userId,
			'cal_id' => $calId,
			'cal_name' => $calName,
			'color' => $color,
		];

		foreach ($this->jobList->getJobsIterator(ImportCalendarJob::class, null, 0) as $job) {
			$args = $job->getArgument();

			if ($args['user_id'] == $argument['user_id'] && $args['cal_id'] == $argument['cal_id']) {
				$job->setArgument($argument);
				return;
			}
		}

		$this->jobList->add(ImportCalendarJob::class, $argument);
	}

	/**
	 * Unregister a calendar to periodically be synced and kept up to date in the
	 * background
	 * @param string $userId
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return void
	 */
	public function unregisterSyncCalendar(string $userId, string $calId): void {

		foreach ($this->jobList->getJobsIterator(ImportCalendarJob::class, null, 0) as $job) {
			/** @var array{user_id: string, cal_id: string} $args */
			$args = $job->getArgument();

			if ($args['user_id'] == $userId && $args['cal_id'] == $calId) {
				$this->jobList->remove($job, $args);
				return;
			}
		}
	}

	/**
	 * @param string $userId
	 * @param string $calId
	 * @param bool $allEvents
	 * @return Generator<Event>
	 */
	private function getCalendarEvents(string $userId, string $calId, bool $allEvents): Generator {
		$params = [
			'maxResults' => 2500,
		];
		if (!$allEvents) {
			$params['eventTypes'] = 'default';
		}
		do {
			$result = $this->googleApiService->request($userId, 'calendar/v3/calendars/' . urlencode($calId) . '/events', $params);
			if (isset($result['error'])) {
				return $result;
			}
			foreach ($result['items'] as $event) {
				yield $event;
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));
		return [];
	}
}
