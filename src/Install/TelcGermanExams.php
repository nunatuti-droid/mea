<?php
declare(strict_types=1);

namespace Mea\Install;

/**
 * Allgemeinsprachliche telc-Deutschprüfungen A1 bis C2.
 * Inhalte sinngemäß nach den Prüfungsseiten auf telc.net (Stand 10/2026). Keine Preise, keine Termine.
 * Prüfungsort ist immer das telc-Prüfungszentrum Herne, persönlich und auf Papier.
 */
final class TelcGermanExams
{
    private const VENUE = '<p>Bei uns legen Sie die Prüfung persönlich und auf Papier im zertifizierten telc-Prüfungszentrum in Herne ab. Online-Prüfungen bieten wir nicht an.</p>';
    private const RECOGNITION = 'telc-Zertifikate werden von Schulen, Hochschulen, Arbeitgebern und Behörden als Sprachnachweis anerkannt. Welcher Nachweis in Ihrem Fall verlangt wird, legt die jeweilige Stelle fest.';

    /** @return array<string, array<string,mixed>> nach URL-Kennung */
    public static function all(): array
    {
        $rec = self::RECOGNITION;
        return [
            'telc-deutsch-a1' => [
                'public_name' => 'telc Deutsch A1', 'internal_name' => 'Start Deutsch 1 / telc Deutsch A1', 'level_label' => 'A1', 'sort_order' => 10,
                'exam_format' => 'Gesamtprüfung (schriftlich und mündlich)',
                'short_text' => 'Start Deutsch 1: Der Nachweis für den Einstieg. Sie zeigen, dass Sie sich in einfachen Alltagssituationen auf Deutsch verständigen können.',
                'body_html' => '<p><strong>Start Deutsch 1 / telc Deutsch A1</strong> ist eine allgemeinsprachliche Deutschprüfung auf der Stufe A1 des Gemeinsamen Europäischen Referenzrahmens (GER). Sie prüft Lesen, Hören, Schreiben und Sprechen.</p>'
                    . '<p>Mit dem Zertifikat weisen Sie nach, dass Sie sich in einfachen Situationen des Alltags verständigen können: sich vorstellen, einfache Fragen stellen und beantworten, kurze Mitteilungen verstehen und schreiben.</p><p>' . $rec . '</p>',
                'target_group_html' => '<p>Für Deutschlernende am Anfang, die grundlegende Deutschkenntnisse für den Alltag nachweisen möchten.</p>',
                'procedure_html' => '<ul><li><strong>Schriftliche Prüfung</strong> (ohne Pause): Hören, 3 Teile, ca. 20 Minuten; Lesen und Schreiben, 3 Leseteile und 2 Schreibteile, 45 Minuten.</li><li><strong>Mündliche Prüfung:</strong> Sprechen, 3 Teile, ca. 15 Minuten, in der Regel in einer Gruppe von vier Teilnehmenden, ohne Vorbereitungszeit.</li><li>Die Prüfung kann nur vollständig abgelegt werden, nicht in Teilen.</li></ul>' . self::VENUE,
                'duration_text' => 'schriftlich ca. 65 Minuten, mündlich ca. 15 Minuten',
            ],
            'telc-deutsch-a2' => [
                'public_name' => 'telc Deutsch A2', 'internal_name' => 'Start Deutsch 2 / telc Deutsch A2', 'level_label' => 'A2', 'sort_order' => 20,
                'exam_format' => 'Gesamtprüfung (schriftlich und mündlich)',
                'short_text' => 'Start Deutsch 2: Sie weisen nach, dass Sie sich in vertrauten Alltagssituationen einfach und verständlich auf Deutsch ausdrücken können.',
                'body_html' => '<p><strong>Start Deutsch 2 / telc Deutsch A2</strong> ist eine allgemeinsprachliche Deutschprüfung auf der Stufe A2 des GER. Sie prüft Lesen, Hören, Schreiben und Sprechen.</p>'
                    . '<p>Das Zertifikat zeigt, dass Sie sich in einfachen, routinemäßigen Situationen des Alltags verständigen können, zum Beispiel beim Einkaufen, bei der Arbeit oder im Gespräch über Familie und Wohnumgebung.</p><p>' . $rec . '</p>',
                'target_group_html' => '<p>Für Deutschlernende mit ersten Vorkenntnissen, die ihre Sprachkenntnisse auf dem Niveau A2 bestätigen lassen möchten.</p>',
                'procedure_html' => '<ul><li><strong>Schriftliche Prüfung</strong> (ohne Pause): Hören, 3 Teile, ca. 20 Minuten; Lesen, 3 Teile, 50 Minuten; Schreiben, 2 Teile.</li><li><strong>Mündliche Prüfung:</strong> Sprechen, 3 Teile, ca. 15 Minuten, in der Regel zu zweit, ohne Vorbereitungszeit.</li><li>Die Prüfung kann nur vollständig abgelegt werden, nicht in Teilen.</li></ul>' . self::VENUE,
                'duration_text' => 'mündlich ca. 15 Minuten',
            ],
            'telc-deutsch-a2-b1' => [
                'public_name' => 'telc Deutsch A2·B1', 'internal_name' => 'telc Deutsch A2·B1', 'level_label' => 'A2-B1', 'sort_order' => 25,
                'exam_format' => 'Skalierte Prüfung (A2 oder B1)',
                'short_text' => 'Eine Prüfung, zwei mögliche Ergebnisse: Je nach Leistung erhalten Sie ein Zertifikat auf A2 oder B1, etwa für Aufenthalt, Einbürgerung oder Beruf.',
                'body_html' => '<p><strong>telc Deutsch A2·B1</strong> ist eine skalierte Deutschprüfung: Sie prüft Lesen, Hören, Schreiben und Sprechen auf den Stufen A2 und B1 des GER. Je nach Ergebnis erhalten Sie ein Zertifikat für das niedrigere (A2) oder das höhere Niveau (B1).</p>'
                    . '<p>Die Themen und der Wortschatz stammen aus dem privaten und beruflichen Alltag. Das Zertifikat kann als Nachweis für B1 dienen und ebenso als Nachweis für A2.</p><p>' . $rec . '</p>',
                'target_group_html' => '<p>Für Deutschlernende, die in Deutschland leben oder hierherziehen möchten, und für alle, die einen anerkannten Sprachnachweis brauchen, zum Beispiel für ein Visum, einen Aufenthaltstitel, die Einbürgerung oder die berufliche Anerkennung.</p>',
                'procedure_html' => '<ul><li><strong>Schriftliche Prüfung:</strong> Lesen, 4 Teile, 45 Minuten; Sprachbausteine (Lesen und Schreiben), 3 Teile, 35 Minuten; Hören (auch Hören und Schreiben), 5 Teile, 35 Minuten; Schreiben, 1 Teil, 10 Minuten.</li><li><strong>Mündliche Prüfung:</strong> Sprechen, 3 Teile, ca. 15 Minuten, in der Regel zu zweit, ohne Vorbereitungszeit.</li></ul>' . self::VENUE,
                'duration_text' => 'schriftlich ca. 2 Stunden, mündlich ca. 15 Minuten',
            ],
            'telc-deutsch-b1' => [
                'public_name' => 'telc Deutsch B1', 'internal_name' => 'Zertifikat Deutsch / telc Deutsch B1', 'level_label' => 'B1', 'sort_order' => 30,
                'exam_format' => 'Gesamtprüfung (schriftlich und mündlich)',
                'short_text' => 'Zertifikat Deutsch: der international anerkannte Nachweis, dass Sie sich im privaten und beruflichen Alltag selbstständig auf Deutsch verständigen.',
                'body_html' => '<p><strong>Zertifikat Deutsch / telc Deutsch B1</strong> ist eine international anerkannte allgemeinsprachliche Prüfung auf der Stufe B1 des GER. Sie prüft Lesen, Schreiben, Hören und Sprechen.</p>'
                    . '<p>Mit dem Zertifikat zeigen Sie, dass Sie sich in Alltagssituationen im privaten und beruflichen Umfeld verständigen können: die Hauptpunkte verstehen, wenn über vertraute Themen gesprochen wird, und sich zusammenhängend zu Erfahrungen, Zielen und Plänen äußern.</p><p>' . $rec . '</p>',
                'target_group_html' => '<p>Für Deutschlernende, die die Grundlagen beherrschen und Deutsch im Alltag sicher anwenden.</p>',
                'procedure_html' => '<ul><li><strong>Schriftliche Prüfung</strong> (ohne Pause): Lesen, 3 Teile, und Sprachbausteine, 2 Teile, zusammen 90 Minuten; Hören, 3 Teile, ca. 30 Minuten; Schreiben, 1 Teil, 30 Minuten.</li><li><strong>Mündliche Prüfung:</strong> Sprechen, 3 Teile, ca. 15 Minuten, als Paarprüfung, mit 20 Minuten Vorbereitungszeit.</li></ul>' . self::VENUE,
                'duration_text' => 'schriftlich ca. 2,5 Stunden, mündlich ca. 15 Minuten plus 20 Minuten Vorbereitung',
            ],
            'telc-deutsch-b2' => [
                'public_name' => 'telc Deutsch B2', 'internal_name' => 'telc Deutsch B2', 'level_label' => 'B2', 'sort_order' => 40,
                'exam_format' => 'Gesamtprüfung oder Teilprüfung',
                'short_text' => 'Der Nachweis für selbstständige Sprachverwendung: Sie verständigen sich auch über komplexere Themen spontan und fließend auf Deutsch.',
                'body_html' => '<p><strong>telc Deutsch B2</strong> ist eine allgemeinsprachliche Deutschprüfung auf der Stufe B2 des GER. Sie prüft Lesen, Hören, Schreiben und Sprechen.</p>'
                    . '<p>Das Zertifikat bestätigt Sprachkenntnisse auf dem Niveau der selbstständigen Sprachverwendung: Sie verstehen die Hauptinhalte komplexer Texte und können sich spontan und fließend verständigen. Die Prüfung kann auch als Teilprüfung abgelegt werden.</p><p>' . $rec . '</p>',
                'target_group_html' => '<p>Für Deutschlernende mit guten Kenntnissen, die ihre Sprachkompetenz zum Beispiel für Beruf, Ausbildung oder Studium nachweisen möchten.</p>',
                'procedure_html' => '<ul><li><strong>Schriftliche Prüfung</strong> (ohne Pause): Lesen, 3 Teile, und Sprachbausteine, 2 Teile, zusammen 90 Minuten; Hören, 3 Teile, ca. 20 Minuten; Schreiben, 1 Teil, 30 Minuten.</li><li><strong>Mündliche Prüfung:</strong> Sprechen, 3 Teile, ca. 15 Minuten, als Paarprüfung, mit 20 Minuten Vorbereitungszeit.</li></ul>' . self::VENUE,
                'duration_text' => 'schriftlich ca. 2,5 Stunden, mündlich ca. 15 Minuten plus 20 Minuten Vorbereitung',
            ],
            'telc-deutsch-c1' => [
                'public_name' => 'telc Deutsch C1', 'internal_name' => 'telc Deutsch C1', 'level_label' => 'C1', 'sort_order' => 50,
                'exam_format' => 'Gesamtprüfung oder Teilprüfung',
                'short_text' => 'Für fortgeschrittene Deutschkenntnisse: Sie drücken sich spontan, fließend und sprachlich genau aus, im Alltag wie im Beruf.',
                'body_html' => '<p><strong>telc Deutsch C1</strong> ist eine allgemeinsprachliche Deutschprüfung auf der Stufe C1 des GER, der Stufe der fortgeschrittenen Sprachverwendung. Sie prüft Lesen, Hören, Schreiben und Sprechen.</p>'
                    . '<p>Auf diesem Niveau können Sie sich spontan und fließend ausdrücken, umgangssprachliche Wendungen verwenden und dabei grammatisch sehr genau bleiben. Die Prüfung kann auch als Teilprüfung abgelegt werden.</p><p>' . $rec . '</p>',
                'target_group_html' => '<p>Für alle, die fortgeschrittene Deutschkenntnisse nachweisen möchten, etwa für anspruchsvolle berufliche Aufgaben.</p>',
                'procedure_html' => '<ul><li><strong>Schriftliche Prüfung:</strong> Lesen, 3 Teile, und Sprachbausteine, 1 Teil, zusammen 90 Minuten; 20 Minuten Pause; Hören, 3 Teile, ca. 40 Minuten; Schreiben, 1 Teil, 70 Minuten.</li><li><strong>Mündliche Prüfung:</strong> Sprechen, 3 Teile, ca. 16 Minuten, in der Regel zu zweit, mit 20 Minuten Vorbereitungszeit.</li></ul>' . self::VENUE,
                'duration_text' => 'schriftlich ca. 3,5 Stunden mit Pause, mündlich ca. 16 Minuten plus 20 Minuten Vorbereitung',
            ],
            'telc-deutsch-c1-hochschule' => [
                'public_name' => 'telc Deutsch C1 Hochschule', 'internal_name' => 'telc Deutsch C1 Hochschule', 'level_label' => 'C1 Hochschule', 'sort_order' => 55,
                'exam_format' => 'Gesamtprüfung (schriftlich und mündlich)',
                'short_text' => 'Die Deutschprüfung für das Studium: Sie weisen fortgeschrittene Deutschkenntnisse für Hochschule und wissenschaftliche Laufbahn nach.',
                'body_html' => '<p><strong>telc Deutsch C1 Hochschule</strong> ist die Deutschprüfung für das Studium. Sie prüft fortgeschrittene Deutschkenntnisse auf der Stufe C1 des GER für Alltagssituationen und für das Umfeld Hochschule.</p>'
                    . '<p>Geprüft werden die sprachlichen Fähigkeiten, die für ein Studium und eine wissenschaftliche Laufbahn gebraucht werden. Ob das Zertifikat für die Zulassung zu Ihrem Studiengang genügt, entscheidet die jeweilige Hochschule.</p><p>' . $rec . '</p>',
                'target_group_html' => '<p>Für Studieninteressierte und Studierende, die Deutschkenntnisse für ein Studium an einer deutschsprachigen Hochschule nachweisen möchten.</p>',
                'procedure_html' => '<ul><li><strong>Schriftliche Prüfung:</strong> Lesen, 3 Teile, und Sprachbausteine, 1 Teil, zusammen 90 Minuten; 20 Minuten Pause; Hören, 3 Teile, ca. 40 Minuten; Schreiben, 1 Teil, 70 Minuten.</li><li><strong>Mündliche Prüfung:</strong> Sprechen, 3 Teile, ca. 16 Minuten, in der Regel zu zweit, mit 20 Minuten Vorbereitungszeit.</li></ul>' . self::VENUE,
                'duration_text' => 'schriftlich ca. 3,5 Stunden mit Pause, mündlich ca. 16 Minuten plus 20 Minuten Vorbereitung',
            ],
            'telc-deutsch-c2' => [
                'public_name' => 'telc Deutsch C2', 'internal_name' => 'telc Deutsch C2', 'level_label' => 'C2', 'sort_order' => 60,
                'exam_format' => 'Gesamtprüfung (schriftlich und mündlich)',
                'short_text' => 'Die höchste Stufe: Sie weisen Deutschkenntnisse nach, die der Muttersprache annähernd entsprechen.',
                'body_html' => '<p><strong>telc Deutsch C2</strong> ist eine allgemeinsprachliche Deutschprüfung auf der höchsten Stufe des GER. Sie prüft Lesen, Hören, Schreiben und Sprechen.</p>'
                    . '<p>Das Zertifikat belegt eine Sprachkompetenz, die der Muttersprache annähernd entspricht. Zur Prüfung gehört auch eine Mittlungsaufgabe, bei der Sie Gehörtes schriftlich wiedergeben.</p><p>' . $rec . '</p>',
                'target_group_html' => '<p>Für Menschen mit sehr hohen Deutschkenntnissen, die diese auf höchstem Niveau bestätigen lassen möchten, etwa für Beruf, Lehre oder Wissenschaft.</p>',
                'procedure_html' => '<ul><li><strong>Schriftliche Prüfung:</strong> Lesen, 3 Teile, 80 Minuten; 20 Minuten Pause; Hören und Schreiben (Mittlungsaufgabe), 1 Teil, 60 Minuten; Schreiben, 1 Teil, 90 Minuten.</li><li><strong>Mündliche Prüfung:</strong> Sprechen, 2 Teile, ca. 15 Minuten, als Einzelprüfung, mit 20 Minuten Vorbereitungszeit.</li></ul>' . self::VENUE,
                'duration_text' => 'schriftlich ca. 4 Stunden mit Pause, mündlich ca. 15 Minuten plus 20 Minuten Vorbereitung',
            ],
        ];
    }
}
