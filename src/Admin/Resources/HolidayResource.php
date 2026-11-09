<?php
declare(strict_types=1);

namespace Mea\Admin\Resources;

use Mea\Admin\Crud\Resource;

final class HolidayResource extends Resource
{
    public string $key = 'feiertage';
    public string $table = 'holidays';
    public string $permission = 'sessions';
    public string $singular = 'Feiertag';
    public string $plural = 'Feiertage';
    public string $entity = 'holiday';
    public string $intro = 'Feiertage erscheinen im Kalender. Mit „Feiertage NRW erzeugen“ werden die gesetzlichen Feiertage in Nordrhein-Westfalen für ein Jahr angelegt.';
    public string $orderBy = 'holiday_date DESC';
    public bool $optimisticLock = false;
    public ?string $indexPanel = 'admin/sessions/holidays_panel.twig';

    public function columns(): array
    {
        return [['key' => 'name', 'label' => 'Name'], ['key' => 'holiday_date', 'label' => 'Datum', 'format' => 'date'], ['key' => 'region', 'label' => 'Region']];
    }

    public function fields(?array $row): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 100],
            ['name' => 'holiday_date', 'label' => 'Datum', 'type' => 'date', 'required' => true],
            ['name' => 'region', 'label' => 'Region', 'required' => true, 'max' => 10, 'default' => 'NW'],
        ];
    }

    /** Gesetzliche Feiertage NRW; Ostern nach der Gaußschen Osterformel (anonymer gregorianischer Algorithmus). @return array<string,string> */
    public static function nrw(int $year): array
    {
        $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100; $d = intdiv($b, 4); $e = $b % 4;
        $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31); $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        $easter = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        $rel = fn(int $days) => $easter->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
        return [
            "$year-01-01" => 'Neujahr', $rel(-2) => 'Karfreitag', $rel(1) => 'Ostermontag', "$year-05-01" => 'Tag der Arbeit',
            $rel(39) => 'Christi Himmelfahrt', $rel(50) => 'Pfingstmontag', $rel(60) => 'Fronleichnam', "$year-10-03" => 'Tag der Deutschen Einheit',
            "$year-11-01" => 'Allerheiligen', "$year-12-25" => '1. Weihnachtstag', "$year-12-26" => '2. Weihnachtstag',
        ];
    }

    public function actions(array $row, \Mea\Admin\AdminContext $ctx): array
    {
        return [];
    }
}
