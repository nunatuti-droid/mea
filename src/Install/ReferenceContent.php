<?php
declare(strict_types=1);

namespace Mea\Install;

/**
 * Inhalte der Bereichsseiten und ihrer Unterseiten (Update 0.9.13/0.9.14). Grundlage sind die Seiten der Referenz
 * (meacampus-npp3gweb.manus.space); die Texte sind neu formuliert, gegliedert und um Erläuterungen, Abläufe und
 * Antworten auf häufige Fragen ergänzt. Aussagen zu Förderung, Zulassung und Prüfungen bleiben bewusst vorsichtig:
 * Zusagen gibt es nur für das konkrete Angebot und nach Klärung mit der zuständigen Stelle.
 *
 * Aufbau je Seite: parent (null = Bereich), slug, title, menu, teaser, icon, topic (Kontaktthema), cta, seo_title,
 * seo_desc, intro, highlights, sections (eyebrow, title, text, cards), process (eyebrow, title, steps), faq, cta_title, cta_text.
 */
final class ReferenceContent
{
    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return [
            // ------------------------------------------------------------ Bildungszentrum
            [
                'parent' => null, 'slug' => 'bildungszentrum', 'title' => 'Bildungszentrum', 'menu' => 'Bildungszentrum',
                'teaser' => 'Berufliche Grundlagen stärken, Sprache aufbauen und den Einstieg in Ausbildung und Beruf vorbereiten.',
                'topic' => 'bildungszentrum', 'cta' => 'Lernweg besprechen',
                'seo_title' => 'Bildungszentrum: Lernen, Sprache, Ausbildung | MEA Marl',
                'seo_desc' => 'Sprache, berufliche Orientierung und Grundlagen für Ausbildung und Arbeit – in Gruppen mit 10 bis 15 Personen oder im Einzelsetting. Jetzt Lernweg besprechen.',
                'intro' => 'Ein berufliches Ziel braucht mehr als einen Kursnamen. Es braucht verständliche Inhalte, Raum zum Üben und eine Begleitung, die Ihre Ausgangslage ernst nimmt. Im MEA Bildungszentrum verbinden wir Sprache, berufliche Orientierung und Grundlagen für Ausbildung und Arbeit. Gemeinsam finden wir heraus, was Sie bereits mitbringen und woran Sie als Nächstes arbeiten möchten.',
                'highlights' => ['Sprache und berufliche Grundlagen zusammen denken', 'Lernen in Gruppen mit 10 bis 15 Personen oder im Einzelsetting', 'Persönliche Orientierung im Ruhrgebiet'],
                'sections' => [
                    ['eyebrow' => 'Ihr Lernziel', 'title' => 'Nicht alles lernen. Das Richtige lernen.',
                        'text' => 'Vielleicht möchten Sie eine Ausbildung beginnen, Arbeitsaufträge besser verstehen oder nach einer Pause wieder beruflich einsteigen. Wir besprechen Ihr Ziel und ordnen die nächsten Lernschritte. Sprachkenntnisse und berufliche Erfahrungen betrachten wir dabei gemeinsam – so wird aus einer allgemeinen Kursanfrage ein klarer Ausgangspunkt für Ihre Planung.',
                        'cards' => [
                            ['title' => 'Berufliche Grundlagen', 'text' => 'Aufgaben lesen, Informationen ordnen und Arbeitsabläufe erklären: Diese Fähigkeiten helfen beim Einstieg in gewerbliche und technische Tätigkeitsfelder. Im Gespräch klären wir, welche Grundlagen für Ihr Ziel wichtig sind.'],
                            ['title' => 'Sprache als Verbindung', 'text' => 'Wer fachlich etwas kann, muss es auch erklären und Rückfragen stellen können. Deshalb gehört berufsbezogene Sprache von Anfang an in die Planung – nicht erst dann, wenn Schwierigkeiten auftreten.'],
                        ]],
                    ['eyebrow' => 'Lernform', 'title' => 'Gemeinsam üben oder individuell vertiefen',
                        'text' => 'In Gruppen mit 10 bis 15 Teilnehmenden profitieren Sie vom Austausch und von gemeinsamen Übungen. In der Einzelbegleitung steht Ihr persönlicher Bedarf im Mittelpunkt. Welche Lernform passt, besprechen wir anhand Ihres Ziels, Ihrer Vorkenntnisse und Ihrer zeitlichen Möglichkeiten. Sie müssen sich vor der Beratung nicht auf ein Format festlegen.'],
                    ['eyebrow' => 'Regional erreichbar', 'title' => 'Ein Lernweg, der in Ihren Alltag passt',
                        'text' => 'MEA ist in Marl, Recklinghausen, Wanne-Eickel und Bochum vertreten. Für Ihre Anfrage klären wir den passenden Unterrichtsort, die Lernform und die organisatorischen Voraussetzungen. Auch die Verbindung mit Coaching oder Ausbildungsvorbereitung gehört in dieses Gespräch. Sie erhalten eine verständliche Übersicht, bevor Sie sich für ein konkretes Angebot entscheiden.'],
                ],
                'process' => ['eyebrow' => 'So geht es weiter', 'title' => 'Aus einem ersten Gespräch wird ein klarer Weg', 'steps' => [
                    ['title' => 'Ziel beschreiben', 'text' => 'Erzählen Sie uns, was Sie beruflich erreichen möchten und welche Erfahrungen Sie bereits gesammelt haben. Eine kurze Beschreibung genügt für die erste Anfrage.'],
                    ['title' => 'Lernbedarf klären', 'text' => 'Wir sprechen über Sprache, Grundlagen und mögliche Lernformen. Vorhandene Nachweise helfen dabei, sind für die erste Kontaktaufnahme aber nicht nötig.'],
                    ['title' => 'Nächsten Schritt planen', 'text' => 'Wir klären Angebot, Ort, Ablauf und Kosten oder einen möglichen Förderweg. Erst danach entscheiden Sie über den passenden Einstieg.'],
                ]],
                'faq' => [
                    ['q' => 'Muss ich schon wissen, welchen Kurs ich brauche?', 'a' => 'Nein. Es genügt, wenn Sie uns Ihr Ziel und Ihre bisherigen Erfahrungen schildern. Gemeinsam klären wir, welches Angebot dazu passt – das ist der Zweck des ersten Gesprächs.'],
                    ['q' => 'Gibt es an jedem Standort dieselben Angebote?', 'a' => 'Nicht unbedingt. Welche Angebote an welchem Standort stattfinden, hängt vom Thema und von der Verfügbarkeit ab. Wir bestätigen Ihnen Ort und Lernform für Ihre konkrete Anfrage.'],
                    ['q' => 'Kann ich vorab die Finanzierung klären?', 'a' => 'Ja, das empfehlen wir sogar. Wir besprechen mit Ihnen Kosten und mögliche Förderwege, bevor Sie sich entscheiden. Über eine öffentliche Förderung entscheidet die zuständige Stelle.'],
                ],
                'cta_title' => 'Ihr nächster Schritt beginnt mit einem Gespräch',
                'cta_text' => 'Berufliche Grundlagen, Ausbildungsvorbereitung und Sprachlernen: Wir besprechen Ihren passenden Lernweg und begleiten den nächsten Schritt.',
            ],
            [
                'parent' => 'bildungszentrum', 'slug' => 'ausbildung', 'title' => 'Ausbildungsvorbereitung', 'menu' => 'Ausbildung', 'icon' => 'graduation-cap',
                'teaser' => 'Grundlagen für den Einstieg in eine Ausbildung', 'topic' => 'bildungszentrum', 'cta' => 'Ausbildungsvorbereitung besprechen',
                'seo_title' => 'Ausbildungsvorbereitung im Ruhrgebiet | MEA Bildungszentrum',
                'seo_desc' => 'Orientierung, Lern- und Sprachgrundlagen und Bewerbungsvorbereitung für den Einstieg in eine Ausbildung – persönlich beraten in Marl, Recklinghausen, Herne und Bochum.',
                'intro' => 'Eine Ausbildung verbindet Lernen im Betrieb mit Unterricht in der Berufsschule. Dafür braucht es Orientierung, Grundlagen und die passende Sprache. Die Ausbildungsvorbereitung setzt vor diesem Übergang an: Wir besprechen Ihre Interessen und Erfahrungen, schauen auf die Anforderungen Ihres Berufsziels und machen sichtbar, was Sie bereits mitbringen und welche Vorbereitung als Nächstes sinnvoll ist.',
                'highlights' => ['Berufswunsch und Anforderungen zusammenbringen', 'Lern- und Sprachgrundlagen gezielt betrachten', 'Nächste Schritte in Richtung Ausbildung planen'],
                'sections' => [
                    ['eyebrow' => 'Orientierung', 'title' => 'Einen Berufswunsch genauer kennenlernen',
                        'text' => 'Ein Berufsname sagt wenig darüber, wie der Arbeitsalltag aussieht. Wir schauen auf typische Aufgaben und Erwartungen, besonders in gewerblichen und technischen Berufsfeldern: Was interessiert Sie daran? Welche Erfahrungen haben Sie schon? Welche Anforderungen sind noch unklar? So lässt sich ein Berufswunsch prüfen, bevor Sie Ihre Vorbereitung darauf ausrichten.',
                        'cards' => [
                            ['title' => 'Interessen und Stärken', 'text' => 'Praktische Erfahrungen, schulische Kenntnisse und persönliche Interessen werden gemeinsam betrachtet. Es geht nicht um eine schnelle Bewertung, sondern darum, Ihren Ausgangspunkt für eine fundierte Entscheidung zu verstehen.'],
                            ['title' => 'Anforderungen verstehen', 'text' => 'Betriebe erwarten nicht nur Fachinteresse. Arbeitsaufträge verstehen, pünktlich sein, im Team kommunizieren und Lernaufgaben bearbeiten gehören ebenfalls dazu. Wir machen diese Erwartungen konkret und besprechbar.'],
                        ]],
                    ['eyebrow' => 'Vorbereitung', 'title' => 'Lernen für Betrieb und Berufsschule verbinden',
                        'text' => 'Lesen, Informationen ordnen und Zusammenhänge erklären sind wichtige Grundlagen. Hinzu kommt Sprache für Fachbegriffe, Anweisungen und Aufgabenstellungen. Wir klären, welche Bereiche für Ihren Einstieg besonders relevant sind. Das Ziel ist eine nachvollziehbare Lernplanung – kein Versprechen, jede Person nach derselben Dauer auf jede Ausbildung vorzubereiten.'],
                    ['eyebrow' => 'Übergang', 'title' => 'Den nächsten Kontakt bewusst vorbereiten',
                        'text' => 'Ein Lebenslauf, ein vorbereitetes Gespräch und klare Fragen zum Ausbildungsalltag erleichtern die Kontaktaufnahme mit Betrieben. Bei Bedarf besprechen wir eine Verbindung zu Coaching oder Talentvermittlung. Möglichkeiten für ein Kennenlernen im Betrieb werden einzeln geprüft und mit Ihnen abgestimmt. Über einen Ausbildungsplatz entscheidet das Unternehmen; formale Ausbildungsfragen gehören zur zuständigen Kammer oder Berufsberatung.'],
                ],
                'process' => ['eyebrow' => 'So geht es weiter', 'title' => 'In drei Schritten zur passenden Vorbereitung', 'steps' => [
                    ['title' => 'Ausgangslage besprechen', 'text' => 'Nennen Sie Ihren Berufswunsch oder die Tätigkeiten, die Sie interessieren. Schulzeugnisse und bisherige Erfahrungen helfen, Ihre Voraussetzungen genauer einzuordnen.'],
                    ['title' => 'Vorbereitung planen', 'text' => 'Wir setzen Schwerpunkte bei Sprache, Grundlagen und Orientierung. Gemeinsam klären wir Lernform, Standort sowie organisatorische und finanzielle Fragen vor dem Beginn.'],
                    ['title' => 'Übergang vorbereiten', 'text' => 'Wir besprechen Bewerbungsunterlagen, Gesprächsvorbereitung und passende Ansprechpartner. Mögliche betriebliche Kontakte werden einzeln geprüft und mit Ihnen abgestimmt.'],
                ]],
                'faq' => [
                    ['q' => 'Muss mein Ausbildungsberuf schon feststehen?', 'a' => 'Nein. Gerade wenn Sie noch schwanken, hilft die Vorbereitung: Wir schauen gemeinsam auf Aufgaben und Anforderungen verschiedener Berufsfelder und prüfen, was zu Ihnen passt.'],
                    ['q' => 'Ist die Vorbereitung eine abgeschlossene Berufsausbildung?', 'a' => 'Nein. Die Ausbildungsvorbereitung bereitet den Übergang vor, sie ersetzt keine Ausbildung und führt nicht zu einem Berufsabschluss.'],
                    ['q' => 'Ist das automatisch eine geförderte Berufsvorbereitende Bildungsmaßnahme (BvB)?', 'a' => 'Nein. Ob und wie eine Förderung möglich ist, hängt vom konkreten Angebot und von der zuständigen Stelle ab. Wir klären das vor dem Beginn mit Ihnen.'],
                ],
                'cta_title' => 'Ihr Weg in die Ausbildung beginnt mit einem Gespräch',
                'cta_text' => 'Erzählen Sie uns von Ihrem Berufswunsch. Wir ordnen gemeinsam die nächsten Schritte.',
            ],
            [
                'parent' => 'bildungszentrum', 'slug' => 'sprachtraining', 'title' => 'Berufsbezogenes Sprachtraining', 'menu' => 'Sprachtraining', 'icon' => 'languages',
                'teaser' => 'Die Sprache lernen, die der Beruf verlangt', 'topic' => 'sprachkurs', 'cta' => 'Sprachtraining anfragen',
                'seo_title' => 'Berufsbezogenes Sprachtraining | MEA Bildungszentrum',
                'seo_desc' => 'Deutsch für Arbeitsalltag, Betrieb und Berufsschule: berufsbegleitend, online oder am Wochenende – mit Bezug zu Gewerbe und Technik.',
                'intro' => 'Eine Arbeitsanweisung verstehen, ein Problem beschreiben oder bei einer Übergabe nichts Wichtiges vergessen: Berufsbezogenes Sprachtraining setzt an solchen Situationen an. Es geht um Sprache, die Sie praktisch anwenden können. Wir besprechen Ihren Lernbedarf und wählen passende Schwerpunkte. Für Gewerbe und Technik stehen konkrete Abläufe, Fachwörter und die Verständigung im Team besonders im Blick.',
                'highlights' => ['Typische Arbeitsgespräche und Aufgaben üben', 'Berufsbegleitende, Online- und Wochenendformate anfragen', 'Prüfungsvorbereitung passend zum Lernziel besprechen'],
                'sections' => [
                    ['eyebrow' => 'Trainingsinhalte', 'title' => 'Von einzelnen Wörtern zu verständlicher Kommunikation',
                        'text' => 'Fachwörter sind wichtig, reichen allein aber nicht aus. Sie müssen Informationen verstehen, in eigenen Worten wiedergeben und bei Unklarheiten nachfragen können. Deshalb üben wir vollständige Situationen statt bloßer Wortlisten. Die Auswahl orientiert sich an Ihrem Berufsziel und an den Aufgaben, die Sie sprachlich sicherer bewältigen möchten.',
                        'cards' => [
                            ['title' => 'Hören und nachfragen', 'text' => 'Arbeitsaufträge aufnehmen, wichtige Angaben erkennen und eine Rückfrage formulieren: Diese Schritte helfen, Missverständnisse früh anzusprechen. Sprachübungen ersetzen dabei keine betriebliche Sicherheitsunterweisung oder fachliche Einweisung.'],
                            ['title' => 'Lesen und schreiben', 'text' => 'Kurze Mitteilungen, Arbeitsinformationen oder einfache Dokumentationen stellen eigene Anforderungen. Im Training lernen Sie, wichtige Inhalte zu erkennen und Informationen klar und nachvollziehbar weiterzugeben.'],
                            ['title' => 'Sprechen und erklären', 'text' => 'Einen Ablauf beschreiben, ein Problem melden und einen Arbeitsschritt erläutern: Sie üben, Fachwörter mit verständlichen Sätzen zu verbinden und auf Fragen Ihrer Gesprächspartner zu reagieren.'],
                        ]],
                    ['eyebrow' => 'Lernformat', 'title' => 'Berufsbegleitend, online oder am Wochenende',
                        'text' => 'Sie können berufsbegleitendes Sprachtraining sowie Online- und Wochenendkurse anfragen. Welches Format aktuell zu Ihrem Alltag passt, klären wir anhand Ihres Sprachstands, Ihres Lernziels und Ihrer zeitlichen Möglichkeiten. Umfang, Ort und Ablauf stimmen wir für das konkrete Angebot ab.'],
                    ['eyebrow' => 'Prüfungsvorbereitung', 'title' => 'Auf Wunsch gezielt auf eine Prüfung hinarbeiten',
                        'text' => 'Neben berufsbezogener Kommunikation bietet MEA Prüfungsvorbereitung an. Welche Prüfung und welches Niveau Sie anstreben, besprechen wir vorab. Die Vorbereitung lässt sich mit berufsbegleitendem Lernen sowie Online- oder Wochenendformaten verbinden, soweit diese im gewünschten Zeitraum verfügbar sind. Die Prüfung selbst ist ein separates Angebot.'],
                ],
                'process' => ['eyebrow' => 'So geht es weiter', 'title' => 'In drei Schritten zum passenden Training', 'steps' => [
                    ['title' => 'Sprachsituationen sammeln', 'text' => 'Notieren Sie zwei oder drei Beispiele aus Arbeit oder Ausbildung. Beschreiben Sie, was beim Verstehen, Sprechen, Lesen oder Schreiben noch schwerfällt.'],
                    ['title' => 'Lernziele auswählen', 'text' => 'Wir priorisieren die wichtigsten Situationen und beziehen vorhandene Kenntnisse ein. Gemeinsam legen wir einen sinnvollen Schwerpunkt für das Training fest.'],
                    ['title' => 'Rahmen vereinbaren', 'text' => 'Wir klären Lernform, Ort, Umfang und Kosten. Im vereinbarten Training werden Situationen geübt, und Rückmeldungen fließen in die weiteren Lernschritte ein.'],
                ]],
                'faq' => [
                    ['q' => 'Brauche ich ein bestimmtes Sprachzertifikat?', 'a' => 'Für die Anfrage nicht. Ein Zertifikat hilft, Ihren Sprachstand einzuordnen, ersetzt aber nicht den Blick auf Ihre beruflichen Aufgaben. Ob für Ihr Vorhaben ein bestimmter Nachweis verlangt wird, klären Sie mit der Stelle, die ihn fordert.'],
                    ['q' => 'Gibt es berufsbegleitende oder Online-Angebote?', 'a' => 'Ja, berufsbegleitende, Online- und Wochenendformate können Sie anfragen. Welches Format im gewünschten Zeitraum verfügbar ist, bestätigen wir für Ihre Anfrage.'],
                    ['q' => 'Ist Einzeltraining ein BAMF-Berufssprachkurs?', 'a' => 'Nein, nicht automatisch. BAMF-Berufssprachkurse folgen eigenen Teilnahme- und Zulassungsregeln. Fragen Sie gezielt nach Status und Zugang des konkreten Angebots – wir erläutern das gern im Gespräch.'],
                ],
                'cta_title' => 'Welche Sprache brauchen Sie im Beruf?',
                'cta_text' => 'Schildern Sie uns zwei oder drei typische Situationen. Wir schlagen Ihnen passende Schwerpunkte und ein Format vor.',
            ],
            [
                'parent' => 'bildungszentrum', 'slug' => 'pruefungen', 'title' => 'Prüfungen und Prüfungsvorbereitung', 'menu' => 'Prüfungen', 'icon' => 'award',
                'teaser' => 'Vorbereitung und Prüfungsangebote klären', 'topic' => 'pruefung', 'cta' => 'Prüfungsart und Termin erfragen',
                'seo_title' => 'telc-Prüfungen A1 bis C1 und Vorbereitung | MEA',
                'seo_desc' => 'telc-Prüfungen von A1 bis C1 und Prüfungsvorbereitung bis C2: Zertifikat und Teilnahmebescheinigung unterscheiden, Termine und Gebühren persönlich klären.',
                'intro' => 'Bei MEA können Sie telc-Prüfungen auf den Niveaus A1 bis C1 anfragen. Prüfungsvorbereitung wird bis C2 angeboten – das bedeutet nicht, dass auch eine C2-Prüfung abgenommen wird. Für den Prüfungsort Herne erfragen Sie bitte den aktuellen Prüfungsstatus, Termine, Gebühren und den Anmeldeweg.',
                'highlights' => ['telc-Prüfungen von A1 bis C1', 'Prüfungsvorbereitung bis C2', 'Aktuellen Status, Termine und Gebühren persönlich erfragen'],
                'sections' => [
                    ['eyebrow' => 'Ihr Prüfungsziel', 'title' => 'telc-Prüfungen A1 bis C1',
                        'text' => 'MEA bietet telc-Prüfungen auf den Niveaus A1, A2, B1, B2 und C1 sowie Prüfungsvorbereitung bis C2 an. C2 bezieht sich dabei ausschließlich auf die Vorbereitung, nicht auf eine Prüfungsabnahme. Für Unternehmen sind außerdem Gruppenprüfungen möglich; die konkreten Möglichkeiten stimmen wir persönlich ab.',
                        'cards' => [
                            ['title' => 'Prüfungszertifikat', 'text' => 'Ein Zertifikat dokumentiert das Ergebnis einer bestimmten Prüfung. Entscheidend sind unter anderem Prüfungsname, Niveau und ausstellende Institution. Ob der Nachweis für Ihr Vorhaben anerkannt wird, klären Sie mit der Stelle, die ihn verlangt.'],
                            ['title' => 'Teilnahmebescheinigung', 'text' => 'Eine Teilnahmebescheinigung bestätigt einen Kursbesuch. Sie ist nicht automatisch ein Sprachprüfungszertifikat und sagt nichts Gleiches über ein erreichtes Niveau aus. Achten Sie deshalb genau darauf, welcher Nachweis verlangt wird.'],
                        ]],
                    ['eyebrow' => 'Organisation', 'title' => 'Aktuelle Termine und Gebühren erfragen',
                        'text' => 'Prüfungsstatus, aktuelle Termine, Gebühren, Anmeldefrist und benötigte Unterlagen erhalten Sie auf Anfrage. Auch die genaue Anschrift des Prüfungsorts Herne und organisatorische Fragen stimmen wir persönlich ab. Eine Anfrage ist noch keine Anmeldung; Ihre Teilnahme wird nach der konkreten Abstimmung bestätigt. Die Prüfungstermine und die Online-Buchung finden Sie unter „Prüfungen“ im Hauptmenü.'],
                    ['eyebrow' => 'Vorbereitung', 'title' => 'Vorbereitung bis C2 ist keine C2-Prüfungsabnahme',
                        'text' => 'MEA bietet Prüfungsvorbereitung bis C2 und Prüfungen bis C1 an. Für die Vorbereitung werden Präsenzformate in Herne und Marl sowie Onlineunterricht beschrieben; kleine Gruppen oder Einzelcoaching können nach Absprache passen. Welche Vorbereitung aktuell verfügbar ist, besprechen wir gemeinsam. Vorbereitung und Prüfungsabnahme bleiben getrennte Angebote.'],
                ],
                'process' => ['eyebrow' => 'So geht es weiter', 'title' => 'In drei Schritten zur Prüfung', 'steps' => [
                    ['title' => 'Anforderung nennen', 'text' => 'Teilen Sie uns den benötigten Prüfungsnamen und das Niveau mit. Nennen Sie auch den Zweck des Nachweises und Ihren gewünschten Zeitraum.'],
                    ['title' => 'Möglichkeiten erfragen', 'text' => 'Wir klären die angefragte Prüfungsart und die aktuellen organisatorischen Angaben. Sie erfahren Termin, Kosten, Unterlagen und den genauen Ablauf der Anmeldung.'],
                    ['title' => 'Anmeldung bewusst abschließen', 'text' => 'Prüfen Sie die bestätigten Angaben und die Teilnahmebedingungen. Planen Sie Vorbereitung und Anreise erst anhand des konkret vereinbarten Termins und Prüfungsorts.'],
                ]],
                'faq' => [
                    ['q' => 'Welche telc-Prüfungen kann ich anfragen?', 'a' => 'Sie können telc-Prüfungen auf den Niveaus A1, A2, B1, B2 und C1 anfragen. Für C2 bieten wir Prüfungsvorbereitung an, aber keine Prüfungsabnahme.'],
                    ['q' => 'Kann ich ohne vorherigen Kursbesuch eine Prüfung anfragen?', 'a' => 'Eine Anfrage ist grundsätzlich auch ohne vorherigen Kursbesuch möglich. Welche Voraussetzungen und Unterlagen für die konkrete Prüfung gelten, klären wir mit Ihnen.'],
                    ['q' => 'Ist jeder Sprachkurs mit dem Deutsch-Test für den Beruf verbunden?', 'a' => 'Nein. Ein Sprachkurs und eine Prüfung sind getrennte Angebote. Welche Prüfung für Ihr Ziel passt und ob sie angeboten wird, besprechen wir vorab.'],
                ],
                'cta_title' => 'Prüfung oder Vorbereitung – wir klären den nächsten Schritt',
                'cta_text' => 'Nennen Sie uns Prüfungsname und Niveau. Wir sagen Ihnen, welche Möglichkeiten es aktuell gibt.',
            ],
            // ------------------------------------------------------------ Recruiting
            [
                'parent' => null, 'slug' => 'recruiting', 'title' => 'Recruiting', 'menu' => 'Recruiting',
                'teaser' => 'Fachkräftegewinnung, Talentvermittlung und internationales Onboarding – mit dem Personalbedarf Ihres Unternehmens im Mittelpunkt.',
                'topic' => 'fachkraefte', 'cta' => 'Personalbedarf besprechen',
                'seo_title' => 'Recruiting & Fachkräftegewinnung aus dem Ausland | MEA',
                'seo_desc' => 'Internationale Fachkräftegewinnung und Talentvermittlung, besonders aus dem Balkan: Bedarf klären, Qualifikationen prüfen, passende Kontakte abstimmen.',
                'intro' => 'MEA unterstützt Unternehmen bei der internationalen Fachkräftegewinnung und Talentvermittlung – besonders mit Blick auf den Balkan. Für Betriebe im Ruhrgebiet nehmen wir gewerbliche und technische Tätigkeiten sowie Ausbildungswege besonders in den Blick. Wir klären den Bedarf, sprechen mit Kandidat:innen über Erfahrungen und Sprachkenntnisse und stimmen passende Kontakte mit beiden Seiten ab.',
                'highlights' => ['Internationales Recruiting, besonders aus dem Balkan', 'Qualifikationen, Sprachbedarf und Betrieb zusammenbringen', 'Gewerbe und Technik im Ruhrgebiet gezielt mitdenken'],
                'sections' => [
                    ['eyebrow' => 'Für Betriebe', 'title' => 'Bedarf, Qualifikationen und Sprache gemeinsam klären',
                        'text' => 'Im persönlichen Erstgespräch betrachten wir die betrieblichen Aufgaben, notwendige Qualifikationen und den tatsächlichen Sprachbedarf. MEA prüft vorliegende Diplome und Qualifikationen und unterstützt bei der Planung von Zuzug und Arbeitsbeginn. Besonders im regionalen Gewerbe und in der Technik übersetzen wir Anforderungen in ein klares Profil – von notwendigen Nachweisen bis zu Fähigkeiten, die im Betrieb entwickelt werden können.',
                        'cards' => [
                            ['title' => 'Aufgaben statt Schlagworte', 'text' => 'Beschreiben Sie Aufgaben, Arbeitszeiten und Einarbeitung. Im Abgleich betrachten wir benötigte Erfahrung, Sprachkenntnisse sowie vorhandene Diplome und Qualifikationen.'],
                            ['title' => 'Entwicklung mitdenken', 'text' => 'Gute Voraussetzungen zeigen sich nicht nur im Lebenslauf. Lernbereitschaft, berufliche Interessen und die Möglichkeit zur Einarbeitung gehören ebenfalls in die Klärung zwischen Betrieb und interessierter Person.'],
                        ]],
                    ['eyebrow' => 'Für Talente', 'title' => 'Internationale Talente und regionale Betriebe verbinden',
                        'text' => 'MEA spricht Kandidat:innen deutschlandweit an und setzt im internationalen Recruiting einen besonderen Schwerpunkt auf den Balkan. Mit interessierten Menschen klären wir Berufsziel, Erfahrung, Qualifikationen und Sprachniveau. Anschließend stimmen wir passende Kontakte mit Betrieb und Person ab; eine Vorstellung erfolgt mit Zustimmung und auf Grundlage eines zuvor besprochenen Profils.'],
                    ['eyebrow' => 'Zusammenarbeit', 'title' => 'Vom Kennenlernen bis zum geplanten Arbeitsstart',
                        'text' => 'Wir begleiten die Kontaktanbahnung und stimmen die nächsten Schritte für Zuzug und Arbeitsbeginn mit den Beteiligten ab. Unterstützung nach dem Einstieg – etwa beim Ankommen und bei der Verständigung im Betrieb – kann ergänzend besprochen werden. Umfang, Zuständigkeiten und Kosten werden vor einer Beauftragung geklärt.'],
                ],
                'process' => ['eyebrow' => 'Ablauf', 'title' => 'Von der Anfrage zum Arbeitsstart', 'steps' => [
                    ['title' => 'Bedarf melden', 'text' => 'Nennen Sie Tätigkeitsfeld, Arbeitsort und die wichtigsten Aufgaben. Beschreiben Sie, ob es um Beschäftigung, Ausbildung oder eine andere Einstiegsfrage geht.'],
                    ['title' => 'Profil abstimmen', 'text' => 'Wir unterscheiden Muss-Anforderungen und Lernmöglichkeiten. Anschließend klären wir, welche Vorbereitung sinnvoll ist und welche Kontakte infrage kommen.'],
                    ['title' => 'Kandidat:innen sprechen und prüfen', 'text' => 'Wir führen Gespräche zu Erfahrung, Motivation und Sprachkenntnissen und prüfen vorliegende Abschlüsse und Nachweise.'],
                    ['title' => 'Kontakt und Begleitung klären', 'text' => 'Für ein passendes Profil stimmen wir die Vorstellung mit beiden Seiten ab. Kennenlernen, Arbeitsstartplanung und zusätzliche Unterstützung erhalten einen eigenen, transparenten Rahmen.'],
                ]],
                'faq' => [
                    ['q' => 'Kann ich hier offene Stellen durchsuchen?', 'a' => 'Nein, MEA Recruiting ist keine Stellenbörse. Wir vermitteln auf Grundlage eines abgestimmten Bedarfs und stellen passende Kontakte her. Wenn Sie eine Stelle suchen, schildern Sie uns Ihr Berufsziel – wir besprechen die Möglichkeiten persönlich.'],
                    ['q' => 'Wie schnell kann eine Stelle besetzt werden?', 'a' => 'Das hängt von Tätigkeit, Qualifikation, Sprachniveau und den organisatorischen Voraussetzungen ab. Einen pauschalen Zeitraum nennen wir deshalb nicht; im Erstgespräch schätzen wir die nächsten Schritte realistisch ein.'],
                    ['q' => 'Können Sie auch nach dem Einstieg unterstützen?', 'a' => 'Ja. Begleitung nach dem Arbeitsstart – etwa beim Ankommen oder bei der Verständigung im Betrieb – kann ergänzend vereinbart werden. Umfang und Kosten klären wir vor der Beauftragung.'],
                ],
                'cta_title' => 'Passung beginnt mit einem klaren Bedarf',
                'cta_text' => 'Beschreiben Sie uns die Aufgaben in Ihrem Betrieb. Wir besprechen, wie wir Sie bei der Personalgewinnung unterstützen können.',
            ],
            [
                'parent' => 'recruiting', 'slug' => 'unternehmen', 'title' => 'Recruiting für Unternehmen', 'menu' => 'Für Unternehmen', 'icon' => 'briefcase',
                'teaser' => 'Personalbedarf und Zusammenarbeit abstimmen', 'topic' => 'fachkraefte', 'cta' => 'Betrieblichen Bedarf besprechen',
                'seo_title' => 'Recruiting für Unternehmen im Ruhrgebiet | MEA',
                'seo_desc' => 'Fachkräftegewinnung, passende Kontakte und internationales Onboarding: Personalbedarf Ihres Unternehmens klären und Zusammenarbeit abstimmen.',
                'intro' => 'Persönliche Fachkräftegewinnung, passende Kontakte und internationales Onboarding: Bei MEA steht der Personalbedarf Ihres Unternehmens im Mittelpunkt. Beschreiben Sie die konkreten Aufgaben und den Bedarf Ihres Betriebs – wir besprechen, welches Angebot dazu passt.',
                'highlights' => ['Ein Einstieg passend zu Ihrem Anliegen', 'Angebote dieses Bereichs persönlich klären', 'Ablauf und Kosten vor dem Beginn besprechen'],
                'sections' => [
                    ['eyebrow' => 'Unser Angebot', 'title' => 'Was MEA für Ihr Unternehmen übernimmt',
                        'text' => 'Je nach Bedarf unterstützen wir einzelne Schritte oder den gesamten Weg von der Bedarfsklärung bis zum Arbeitsstart.',
                        'cards' => [
                            ['title' => 'Bedarf und Profil', 'text' => 'Wir übersetzen Aufgaben, Arbeitszeiten und Anforderungen in ein klares Anforderungsprofil und unterscheiden Muss-Kriterien von Entwicklungsmöglichkeiten.'],
                            ['title' => 'Talentvermittlung', 'text' => 'Wir sprechen Kandidat:innen an, klären Erfahrung, Qualifikation und Sprachniveau und stimmen Vorstellungen mit beiden Seiten ab.'],
                            ['title' => 'Onboarding', 'text' => 'Sprache, Abläufe und organisatorische Fragen werden für den Arbeitsstart zusammengedacht. Mehr dazu unter Onboarding.'],
                        ]],
                    ['eyebrow' => 'Unsere Arbeitsweise', 'title' => 'Verständlich besprechen. Verbindlich abstimmen.',
                        'text' => 'Wir nehmen Ihr Ziel und Ihre Ausgangslage auf und erklären die nächsten Schritte in klaren Worten. Inhalte, Umfang, Ort und Kosten werden für das konkrete Angebot vor dem Einstieg geklärt. Wo eine andere Stelle über Förderung oder formale Voraussetzungen entscheidet, benennen wir diese Zuständigkeit und stimmen die erforderlichen Schritte ab.'],
                ],
                'faq' => [
                    ['q' => 'Wie beginne ich meine Anfrage?', 'a' => 'Beschreiben Sie kurz Tätigkeitsfeld, Arbeitsort und die wichtigsten Aufgaben. Eine kurze Nachricht oder ein Anruf genügt; vertrauliche Unterlagen sind für die erste Anfrage nicht nötig.'],
                    ['q' => 'Ist mit einer Anfrage schon ein Angebot vereinbart?', 'a' => 'Nein. Eine Anfrage ist unverbindlich. Ein Angebot entsteht erst, wenn Umfang, Zuständigkeiten und Kosten gemeinsam geklärt sind.'],
                ],
                'cta_title' => 'Besprechen Sie Ihren Personalbedarf mit uns',
                'cta_text' => 'Wir hören zu, ordnen den Bedarf und schlagen den passenden Einstieg vor.',
            ],
            [
                'parent' => 'recruiting', 'slug' => 'onboarding', 'update_only' => true, 'title' => 'Onboarding für internationale Fachkräfte', 'menu' => 'Onboarding',
                'teaser' => 'Internationale Mitarbeitende beim Ankommen begleiten', 'topic' => 'fachkraefte', 'cta' => 'Onboarding besprechen',
                'seo_title' => 'Onboarding internationaler Mitarbeitender | MEA Recruiting',
                'seo_desc' => 'Sprache, Zusammenarbeit und organisatorische Fragen in einem abgestimmten Onboarding verbinden – für einen sicheren Start im Betrieb.',
                'intro' => 'Der Arbeitsstart ist leichter, wenn neue Mitarbeitende nicht alles allein herausfinden müssen. MEA unterstützt Unternehmen dabei, internationale Mitarbeitende beim Ankommen und im betrieblichen Alltag zu begleiten. Sprache, Zusammenarbeit und organisatorische Fragen werden in einem abgestimmten Onboarding zusammengedacht.',
                'highlights' => ['Berufsbezogene Sprache und betriebliche Abläufe verbinden', 'Organisatorische Fragen und nächste Schritte gemeinsam ordnen', 'Anerkennungs- und Behördenfragen mit zuständigen Stellen koordinieren'],
                'sections' => [
                    ['eyebrow' => 'Ankommen im Betrieb', 'title' => 'Sprache, Aufgaben und Zusammenarbeit verbinden',
                        'text' => 'Neue Mitarbeitende lernen nicht nur einen Arbeitsplatz kennen, sondern auch Abläufe, Erwartungen und Kommunikationswege. Wir verbinden berufsbezogene Sprache mit konkreten Situationen im Betrieb, damit Arbeitsaufträge, Rückfragen und die Zusammenarbeit im Team von Anfang an gelingen.',
                        'cards' => [
                            ['title' => 'Sprache im Arbeitsalltag', 'text' => 'Berufsbezogenes Sprachtraining hilft, Arbeitsanweisungen, Übergaben und Rückfragen besser zu bewältigen. Die Schwerpunkte richten sich nach Tätigkeit und tatsächlichem Bedarf.'],
                            ['title' => 'Orientierung für Mitarbeitende und Betrieb', 'text' => 'Wir unterstützen die Abstimmung zu Abläufen, Ansprechpersonen und nächsten Schritten. So wissen Betrieb und Mitarbeitende, welche Fragen gemeinsam zu klären sind.'],
                        ]],
                    ['eyebrow' => 'Organisatorische Unterstützung', 'title' => 'Fragen ordnen und passende Stellen einbeziehen',
                        'text' => 'Beim Ankommen entstehen Fragen zu Unterlagen, Behördenwegen, Wohnung und Umzug oder zur Anerkennung ausländischer Qualifikationen. MEA hilft, diese Anliegen zu strukturieren und die Abstimmung mit den zuständigen Stellen zu koordinieren. Welche Unterstützung sinnvoll ist, hängt von der individuellen Situation ab.'],
                    ['eyebrow' => 'Gemeinsam planen', 'title' => 'Onboarding passend zum Arbeitsstart gestalten',
                        'text' => 'Im Gespräch klären wir, welche Mitarbeitenden beteiligt sind, welche betrieblichen Abläufe wichtig sind und wo Unterstützung gebraucht wird. Recruiting, Onboarding und Sprachtraining können aufeinander aufbauen. Zuständigkeiten und Umfang werden vor Beginn gemeinsam abgestimmt.'],
                ],
                'process' => ['eyebrow' => 'Ablauf', 'title' => 'In drei Schritten zum gelungenen Start', 'steps' => [
                    ['title' => 'Bedarf beschreiben', 'text' => 'Nennen Sie den geplanten Arbeitsstart, das Tätigkeitsfeld und die Fragen, bei denen Mitarbeitende oder Betrieb Unterstützung wünschen.'],
                    ['title' => 'Unterstützung abstimmen', 'text' => 'Wir ordnen Themen wie Sprache, betriebliche Orientierung und organisatorische Anliegen und klären, wer dafür einbezogen werden sollte.'],
                    ['title' => 'Ankommen begleiten', 'text' => 'Auf dieser Grundlage vereinbaren wir passende nächste Schritte und den Rahmen der Begleitung.'],
                ]],
                'modules_title' => 'Was zum Onboarding gehören kann',
                'faq' => [
                    ['q' => 'Unterstützt MEA bei Behörden- und Anerkennungsfragen?', 'a' => 'Wir helfen, Anliegen zu ordnen, und koordinieren die Abstimmung mit den zuständigen Stellen. Entscheidungen über Anerkennung, Aufenthalt oder Genehmigungen treffen allein die Behörden.'],
                    ['q' => 'Kann MEA ein Visum oder die Anerkennung garantieren?', 'a' => 'Nein. Visum, Aufenthaltsstatus und Anerkennung ausländischer Abschlüsse entscheiden die zuständigen Stellen. Wir begleiten den Weg, können aber keinen Ausgang garantieren.'],
                    ['q' => 'Lässt sich Onboarding mit Recruiting verbinden?', 'a' => 'Ja. Recruiting, Onboarding und Sprachtraining können aufeinander aufbauen, sodass Erkenntnisse aus der Vermittlung in die Begleitung einfließen.'],
                ],
                'cta_title' => 'Gut ankommen. Im Betrieb sicher starten.',
                'cta_text' => 'Sprechen Sie mit uns über den Arbeitsstart Ihrer neuen Mitarbeitenden.',
            ],
            // ------------------------------------------------------------ Coaching & Beratung
            [
                'parent' => null, 'slug' => 'coaching', 'title' => 'Coaching & Beratung', 'menu' => 'Coaching & Beratung',
                'teaser' => 'Berufliche Ziele klären, persönliche Stärken erkennen und den nächsten Schritt mit individueller Begleitung planen.',
                'topic' => 'avgs', 'cta' => 'Coaching-Anliegen besprechen',
                'seo_title' => 'Coaching & Beratung: 1:1-Angebote | MEA Marl & Herne',
                'seo_desc' => 'MEA Plus, Stabilisierung von Beschäftigungsverhältnissen und Ganzheitliche Betreuung nach § 16k SGB II: drei 1:1-Angebote, passend zu Ihrer Situation.',
                'intro' => 'MEA bietet drei berufsbezogene Einzelangebote (1:1) an: MEA Plus – Berufsbezogenes Kommunikationstraining (120 UE, bis zu 3 Monate), Stabilisierung von Beschäftigungsverhältnissen (72 UE, bis zu 6 Monate) sowie Ganzheitliche Betreuung nach § 16k SGB II (310 UE, bis zu 12 Monate). Welche Maßnahme zu Ihrer Situation passt, klären wir im persönlichen Gespräch. Aktuelle Zulassung und Verfügbarkeit bestätigen wir für das konkrete Angebot.',
                'highlights' => ['Drei Einzelangebote mit unterschiedlichen Schwerpunkten', 'Umfang von 72 bis 310 UE und bis zu 12 Monate', 'Passendes Angebot, Zulassung und Verfügbarkeit persönlich klären'],
                'sections' => [
                    ['eyebrow' => 'MEA 1:1-Angebote', 'title' => 'Drei Formate für unterschiedliche Anliegen',
                        'text' => 'Die drei MEA-Coachings setzen unterschiedliche Schwerpunkte und haben jeweils einen festgelegten zeitlichen Rahmen. Inhalte und Zugang stimmen wir auf Ihre Situation ab. Die Einheiten (UE) sind Unterrichtseinheiten.',
                        'cards' => [
                            ['title' => 'MEA Plus – Berufsbezogenes Kommunikationstraining', 'text' => 'Im 1:1-Format arbeiten Sie an berufsbezogener Kommunikation. Der Umfang beträgt 120 UE innerhalb von bis zu drei Monaten.'],
                            ['title' => 'Stabilisierung von Beschäftigungsverhältnissen', 'text' => 'Das Einzelcoaching unterstützt bei Anliegen rund um den Erhalt einer Beschäftigung und umfasst 72 UE innerhalb von bis zu sechs Monaten.'],
                            ['title' => 'Ganzheitliche Betreuung nach § 16k SGB II', 'text' => 'Die individuelle Begleitung umfasst 310 UE innerhalb von bis zu zwölf Monaten. Sie ist ein eigenes Angebot nach § 16k SGB II und nicht pauschal ein AVGS-Coaching.'],
                        ]],
                    ['eyebrow' => 'Umsetzung', 'title' => 'Berufliche Schritte konkret bearbeiten',
                        'text' => 'Je nach Maßnahme können berufliche Perspektiven, Bewerbungsmanagement, die Kommunikation mit Arbeitgebern und die Unterstützung während der Probezeit im Mittelpunkt stehen. Bei der Ganzheitlichen Betreuung nach § 16k SGB II kann gegebenenfalls auch die Bedarfsgemeinschaft einbezogen werden. Die Inhalte richten sich nach dem jeweiligen Angebot und Ihrer Situation.'],
                    ['eyebrow' => 'Zugang und Förderweg', 'title' => 'Maßnahme und Finanzierung passend klären',
                        'text' => 'MEA Plus und die Stabilisierung von Beschäftigungsverhältnissen sind berufsbezogene Coachingangebote. Die Ganzheitliche Betreuung nach § 16k SGB II ist davon als eigener Förderweg zu unterscheiden und nicht pauschal über einen AVGS finanzierbar. Welche Voraussetzungen, Zulassungen und Verfügbarkeiten aktuell gelten, prüfen wir im persönlichen Gespräch; eine Förderung bestätigt die zuständige Stelle.'],
                ],
                'process' => ['eyebrow' => 'So geht es weiter', 'title' => 'Vom Anliegen zu konkreten Schritten', 'steps' => [
                    ['title' => 'Anliegen nennen', 'text' => 'Beschreiben Sie kurz, was Sie verändern möchten oder wobei Sie Unterstützung brauchen. Ihr berufliches Ziel muss noch nicht fertig formuliert sein.'],
                    ['title' => 'Ziele vereinbaren', 'text' => 'Wir legen gemeinsam fest, welche Themen im Mittelpunkt stehen und woran Sie erkennen, dass Sie einen Schritt weitergekommen sind.'],
                    ['title' => 'Schritte bearbeiten', 'text' => 'Wir arbeiten an den vereinbarten Themen, besprechen Rückmeldungen und passen die Planung an. Aufgaben zwischen den Gesprächen machen die Umsetzung greifbar.'],
                ]],
                'faq' => [
                    ['q' => 'Was unterscheidet Beratung und Coaching?', 'a' => 'Beratung gibt Orientierung und Informationen, zum Beispiel zu Angeboten und Förderwegen. Coaching begleitet Sie über einen vereinbarten Zeitraum dabei, eigene Ziele zu entwickeln und umzusetzen.'],
                    ['q' => 'Kann ich auch ohne fertigen Lebenslauf anfragen?', 'a' => 'Ja. Für die erste Anfrage brauchen Sie keine Unterlagen. Falls im Verlauf ein Lebenslauf nötig ist, erarbeiten wir ihn gemeinsam.'],
                    ['q' => 'Sind alle Coachingangebote AVGS-Maßnahmen?', 'a' => 'Nein. Die Ganzheitliche Betreuung nach § 16k SGB II ist ein eigenes Angebot mit eigenem Zugang. Wir klären im Gespräch, welcher Förderweg für welches Angebot gilt.'],
                ],
                'cta_title' => 'Aus offenen Fragen wird ein nächster Schritt',
                'cta_text' => 'Schildern Sie uns Ihr Anliegen. Wir besprechen, welches Angebot zu Ihrer Situation passt.',
            ],
            [
                'parent' => 'coaching', 'slug' => 'foerderung', 'title' => 'Förderung, AVGS und Bildungsgutschein', 'menu' => 'Förderung & AZAV', 'icon' => 'file-text',
                'teaser' => 'Voraussetzungen und Förderwege verständlich erklärt', 'topic' => 'avgs', 'cta' => 'Fragen zu Angebot und Förderung stellen',
                'seo_title' => 'Förderung, AVGS, Bildungsgutschein & AZAV erklärt | MEA',
                'seo_desc' => 'Welcher Förderweg passt? AVGS, Bildungsgutschein, § 16k SGB II, Sprachförderung und AZAV verständlich erklärt – Finanzierung vor dem Start klären.',
                'intro' => 'Coaching, Weiterbildung und Sprachlernen haben unterschiedliche Finanzierungswege. Entscheidend sind Ihr Ziel, Ihre persönliche Situation und das konkrete Angebot. Wir helfen Ihnen, die Fragen für den nächsten Beratungsschritt zu ordnen. Über eine öffentliche Förderung entscheidet die zuständige Stelle. Klären Sie die Finanzierung deshalb vor dem Beginn – nicht erst, wenn ein Kurs oder Coaching schon läuft.',
                'highlights' => ['AVGS und Bildungsgutschein unterscheiden', 'Zulassung und konkretes Angebot zusammen prüfen', 'Bewilligung und Kosten vor dem Start klären'],
                'sections' => [
                    ['eyebrow' => 'Die wichtigsten Begriffe', 'title' => 'Welcher Weg passt zu welchem Ziel?',
                        'text' => 'Ein Gutschein ist kein frei einsetzbares Zahlungsmittel für jedes Bildungsangebot. Er beschreibt einen bestimmten Förderzweck und kann zeitliche, regionale oder inhaltliche Grenzen enthalten. Nehmen Sie ihn deshalb in die Beratung mit und lassen Sie prüfen, ob das gewünschte Angebot und die vorgesehenen Bedingungen zusammenpassen.',
                        'cards' => [
                            ['title' => 'AVGS', 'text' => 'Der Aktivierungs- und Vermittlungsgutschein betrifft bestimmte Leistungen zur Aktivierung und beruflichen Eingliederung. Ein passendes Coaching kann dazugehören. Für Coaching besteht grundsätzlich kein allgemeiner Anspruch auf die Ausgabe eines AVGS.'],
                            ['title' => 'Ganzheitliche Betreuung nach § 16k SGB II', 'text' => 'Dieses MEA-Angebot ist von einem AVGS zu unterscheiden und nicht pauschal eine AVGS-Maßnahme. Ob die Voraussetzungen im Einzelfall vorliegen und wie der Zugang erfolgt, klärt die zuständige Stelle; Angebotsstatus und Verfügbarkeit bestätigen wir persönlich.'],
                            ['title' => 'Bildungsgutschein', 'text' => 'Ein Bildungsgutschein ist ein Förderweg für berufliche Weiterbildung. Bildungsziel, zugelassener Träger und konkrete Maßnahme müssen zusammenpassen. Er ist nicht einfach eine andere Bezeichnung für einen Coaching-Gutschein.'],
                            ['title' => 'Sprachförderung', 'text' => 'BAMF-Berufssprachkurse folgen eigenen Teilnahme- und Zulassungsregeln. Allgemeines berufsbezogenes Sprachtraining ist nicht automatisch ein solcher Kurs. Fragen Sie gezielt nach Status und Zugang des konkreten Angebots.'],
                        ]],
                    ['eyebrow' => 'AZAV einordnen', 'title' => 'Eine Zulassung hat einen konkreten Geltungsbereich',
                        'text' => 'AZAV steht für Akkreditierungs- und Zulassungsverordnung Arbeitsförderung. Fachkundige Stellen prüfen Träger und bestimmte Maßnahmen. Eine Trägerzulassung allein bedeutet nicht, dass jedes Angebot an jedem Ort gefördert werden kann. Maßgeblich sind die aktuellen Nachweise und ihr Geltungsbereich. Diese Seite erklärt den Förderkontext; sie ist kein Angebot zur Zertifizierungsberatung für andere Bildungsträger.'],
                    ['eyebrow' => 'Ihre Vorbereitung', 'title' => 'Mit klaren Fragen in die Förderberatung',
                        'text' => 'Notieren Sie Ihr berufliches Ziel, das gewünschte Angebot und vorhandene Unterlagen. Fragen Sie Ihre Ansprechperson beim Jobcenter oder der Agentur für Arbeit nach dem passenden Förderweg und den erforderlichen Schritten. Bei MEA klären Sie anschließend den aktuellen Status des angefragten Angebots sowie Inhalte und Rahmenbedingungen. Eine öffentliche Förderung sagen wir nicht pauschal zu.'],
                ],
                'process' => ['eyebrow' => 'So geht es weiter', 'title' => 'In drei Schritten zur geklärten Finanzierung', 'steps' => [
                    ['title' => 'Ziel und Bedarf benennen', 'text' => 'Beschreiben Sie, ob es um berufliche Orientierung, Weiterbildung oder Sprache geht. Damit kann die zuständige Stelle den möglichen Förderweg einordnen.'],
                    ['title' => 'Unterlagen und Angebot prüfen', 'text' => 'Lassen Sie Gutscheinbedingungen, Teilnahmevoraussetzungen und erforderliche Zulassungen abgleichen. Fragen Sie auch nach dem zuständigen Vertragspartner und dem vorgesehenen Durchführungsort.'],
                    ['title' => 'Bestätigung abwarten', 'text' => 'Beginnen Sie erst, wenn die notwendige Bewilligung und Finanzierung geklärt sind. Ohne bestätigte Kostenübernahme gilt ein Angebot nicht als für Sie kostenlos.'],
                ]],
                'faq' => [
                    ['q' => 'Kann ich jeden Gutschein bei MEA einlösen?', 'a' => 'Nein. Ein Gutschein gilt für einen bestimmten Förderzweck. Ob er zu einem konkreten MEA-Angebot passt, prüfen wir gemeinsam mit Ihnen und der zuständigen Stelle, am besten vor dem Beginn.'],
                    ['q' => 'Wer entscheidet über eine Kostenübernahme?', 'a' => 'Die zuständige Stelle, zum Beispiel Jobcenter oder Agentur für Arbeit. MEA kann Angebot, Inhalte und Zulassung erläutern, die Entscheidung über die Förderung treffen wir nicht.'],
                    ['q' => 'Kann ich auch ohne Förderung nach einem Angebot fragen?', 'a' => 'Ja. Sie können jederzeit unverbindlich anfragen. Wir besprechen Inhalte, Ablauf und Kosten, sodass Sie in Ruhe entscheiden können.'],
                ],
                'cta_title' => 'Erst den Förderweg klären. Dann den Einstieg planen.',
                'cta_text' => 'Stellen Sie uns Ihre Fragen zu Angebot und Förderung – wir ordnen sie gemeinsam mit Ihnen.',
            ],
            [
                'parent' => 'coaching', 'slug' => 'teilnehmende', 'title' => 'Coaching für Teilnehmende', 'menu' => 'Für Teilnehmende', 'icon' => 'user-check',
                'teaser' => 'Ihr persönliches Anliegen und die nächsten Schritte', 'topic' => 'avgs', 'cta' => 'Mit Coaching & Beratung sprechen',
                'seo_title' => 'Coaching für Teilnehmende | MEA Coaching & Beratung',
                'seo_desc' => 'Persönliches Einzelcoaching, berufliche Orientierung und Beschäftigungsstabilisierung: Anliegen klären und passenden Zugang finden.',
                'intro' => 'Persönliches Einzelcoaching, berufliche Orientierung und Beschäftigungsstabilisierung: Gemeinsam klären wir Ihr Anliegen und den passenden Zugang. Erzählen Sie uns, was Sie erreichen möchten. Sie müssen vor der ersten Anfrage noch kein bestimmtes Angebot ausgewählt haben.',
                'highlights' => ['Ein Einstieg passend zu Ihrem Anliegen', 'Angebote dieses Bereichs persönlich klären', 'Ablauf und Kosten vor dem Beginn besprechen'],
                'sections' => [
                    ['eyebrow' => 'Ihr Anliegen', 'title' => 'Womit wir Sie unterstützen können',
                        'text' => 'Ob Sie sich beruflich neu orientieren, Ihre Kommunikation im Job stärken oder Ihre Beschäftigung sichern möchten: Wir beginnen bei dem, was Sie beschäftigt, und besprechen, welches der drei Einzelangebote passen kann.',
                        'cards' => [
                            ['title' => 'Berufliche Orientierung', 'text' => 'Stärken erkennen, Ziele klären und den nächsten Schritt planen.'],
                            ['title' => 'Kommunikation im Beruf', 'text' => 'Sicherer mit Arbeitgebern, Kolleginnen und Kollegen sprechen.'],
                            ['title' => 'Beschäftigung sichern', 'text' => 'Unterstützung in der Probezeit und bei Fragen rund um den Erhalt einer Stelle.'],
                        ]],
                    ['eyebrow' => 'Unsere Arbeitsweise', 'title' => 'Verständlich besprechen. Verbindlich abstimmen.',
                        'text' => 'Wir nehmen Ihr Ziel und Ihre Ausgangslage auf und erklären die nächsten Schritte in klaren Worten. Inhalte, Umfang, Ort und Kosten werden für das konkrete Angebot vor dem Einstieg geklärt. Wo eine andere Stelle über Förderung oder formale Voraussetzungen entscheidet, benennen wir diese Zuständigkeit und stimmen die erforderlichen Schritte ab.'],
                ],
                'faq' => [
                    ['q' => 'Wie beginne ich meine Anfrage?', 'a' => 'Schreiben oder rufen Sie uns an und schildern Sie kurz Ihr Anliegen. Unterlagen sind dafür nicht nötig; alles Weitere klären wir im Gespräch.'],
                    ['q' => 'Ist mit einer Anfrage schon ein Angebot vereinbart?', 'a' => 'Nein. Eine Anfrage ist unverbindlich. Erst wenn Angebot, Zugang und Finanzierung geklärt sind, entscheiden Sie über den Einstieg.'],
                ],
                'cta_title' => 'Ihr nächster Schritt beginnt mit einem Gespräch',
                'cta_text' => 'Erzählen Sie uns, was Sie erreichen möchten. Wir finden gemeinsam das passende Angebot.',
            ],
            [
                'parent' => 'coaching', 'slug' => 'jobcenter', 'title' => 'Informationen für Jobcenter und Arbeitsvermittlung', 'menu' => 'Für Jobcenter', 'icon' => 'building',
                'teaser' => 'Konkrete Coachinginformationen anfragen', 'topic' => 'avgs', 'cta' => 'Konkretes Angebot anfragen',
                'seo_title' => 'Coaching: Informationen für Jobcenter | MEA',
                'seo_desc' => 'Konkrete Angebote statt pauschaler Zuweisung: Zugang, Nachweise und Dokumentation für Jobcenter und Arbeitsvermittlung im Einzelfall klären.',
                'intro' => 'Persönliches Einzelcoaching, berufliche Orientierung und Beschäftigungsstabilisierung: Für Jobcenter und Arbeitsvermittlung klären wir das konkrete Angebot, die Zugangsvoraussetzungen und die erforderlichen Nachweise im Einzelfall.',
                'highlights' => ['Konkretes Angebot statt pauschaler Zuweisung', 'Zugang und Nachweise individuell klären', 'Dokumentation vorab abstimmen'],
                'sections' => [
                    ['eyebrow' => 'Angebote im Überblick', 'title' => 'Drei 1:1-Angebote mit festem Rahmen',
                        'cards' => [
                            ['title' => 'MEA Plus', 'text' => 'Berufsbezogenes Kommunikationstraining: 120 UE, bis zu 3 Monate.'],
                            ['title' => 'Stabilisierung', 'text' => 'Stabilisierung von Beschäftigungsverhältnissen: 72 UE, bis zu 6 Monate.'],
                            ['title' => 'Ganzheitliche Betreuung', 'text' => 'Nach § 16k SGB II: 310 UE, bis zu 12 Monate. Eigener Förderweg, nicht pauschal AVGS.'],
                        ]],
                    ['eyebrow' => 'Zusammenarbeit', 'title' => 'Verständlich besprechen. Verbindlich abstimmen.',
                        'text' => 'Vor Beginn stimmen wir Ansprechpartner, Kommunikationswege und den benötigten Dokumentationsrahmen ab. Umfang und Weitergabe richten sich nach Auftrag und datenschutzrechtlicher Grundlage. Für eine erste Anfrage reichen Angaben zum Bedarf ohne personenbezogene Unterlagen. Über eine öffentliche Förderung entscheidet die zuständige Stelle.'],
                ],
                'faq' => [
                    ['q' => 'Wie beginne ich meine Anfrage?', 'a' => 'Nennen Sie uns kurz den Bedarf und das gewünschte Angebot. Personenbezogene Unterlagen sind für die erste Anfrage nicht nötig.'],
                    ['q' => 'Ist mit einer Anfrage schon ein Angebot vereinbart?', 'a' => 'Nein. Zuweisung, Umfang und Dokumentation werden vor Beginn gemeinsam abgestimmt und gelten erst danach als vereinbart.'],
                ],
                'cta_title' => 'Angebotsklärung mit MEA Coaching & Beratung',
                'cta_text' => 'Wir beantworten Ihre Fragen zu Umfang, Zulassung und Ablauf – direkt und verbindlich.',
            ],
            // ------------------------------------------------------------ Sprache & Beruf
            [
                'parent' => null, 'slug' => 'sprache-beruf', 'title' => 'Sprache & Beruf', 'menu' => 'Sprache & Beruf',
                'teaser' => 'Berufsbezogenes Deutsch, praxisnahes Sprachtraining und Prüfungsvorbereitung für Ausbildung, Betrieb und Alltag.',
                'topic' => 'sprachkurs', 'cta' => 'Sprachbedarf besprechen',
                'seo_title' => 'Sprache & Beruf: Berufsdeutsch und Prüfungen | MEA',
                'seo_desc' => 'Deutsch mit beruflichen Aufgaben verbinden: Berufsdeutsch, telc-Prüfungen und Sprachtraining für Betrieb, Ausbildung und Berufsschule.',
                'intro' => 'Sie bringen Fähigkeiten mit. Doch Arbeitsaufträge, Fachwörter oder Gespräche im Team sind auf Deutsch noch schwierig? Sprache ist oft die entscheidende Verbindung zwischen dem, was jemand kann, und dem, was im Beruf sichtbar wird. MEA Sprache & Beruf richtet den Blick deshalb auf konkrete Situationen: im Betrieb, auf dem Weg in die Ausbildung und bei der beruflichen Neuorientierung.',
                'highlights' => ['Deutsch mit beruflichen Aufgaben verbinden', 'Betrieb, Ausbildung und Berufsschule mitdenken', 'Lernziel aus Ihrem tatsächlichen Bedarf ableiten'],
                'sections' => [
                    ['eyebrow' => 'Drei Ausgangspunkte', 'title' => 'Welche Sprache brauchen Sie als Nächstes?',
                        'text' => 'Ein allgemeiner Sprachstand allein erklärt noch nicht, welche Unterstützung im Beruf hilft. Wir fragen nach den Situationen, die Ihnen Schwierigkeiten machen, und nach Ihrem Ziel. Dabei betrachten wir Sprechen, Hören, Lesen und Schreiben. Auch Unternehmen können mit konkreten Beispielen aus ihrem Arbeitsalltag in die Beratung einsteigen.',
                        'cards' => [
                            ['title' => 'Einstieg in Arbeit', 'text' => 'Eigene Erfahrungen erklären, eine Stellenanforderung verstehen und im Vorstellungsgespräch nachfragen: Berufliche Orientierung und Sprache gehören zusammen. Ein klares Ziel hilft, die passenden Übungsschwerpunkte zu finden.'],
                            ['title' => 'Sicherer im Betrieb', 'text' => 'Arbeitsaufträge, Übergaben und Rückfragen brauchen klare Worte. Besonders in Gewerbe und Technik zählt, dass wichtige Informationen verstanden werden und Unklarheiten rechtzeitig angesprochen werden können.'],
                            ['title' => 'Vor und während der Ausbildung', 'text' => 'Im Betrieb und in der Berufsschule werden unterschiedliche Texte und Gespräche gebraucht. Fachbegriffe, Aufgabenstellungen und das Erklären von Zusammenhängen sollten deshalb gemeinsam betrachtet werden.'],
                        ]],
                    ['eyebrow' => 'Passender Lernweg', 'title' => 'Sprachstand und Berufsziel gemeinsam betrachten',
                        'text' => 'Sprachniveaus von A1 bis C2 beschreiben allgemeine Sprachkenntnisse. Ein Zertifikat kann bei der Einordnung helfen, ersetzt aber nicht den Blick auf Ihre beruflichen Aufgaben. In der Beratung klären wir, welche Fertigkeiten Sie stärken möchten und welches Lernformat passt. Berufsbezogenes Sprachtraining ist dabei ein eigener Baustein, der sich mit Coaching und Ausbildungsvorbereitung verbinden lässt.'],
                    ['eyebrow' => 'Für Unternehmen', 'title' => 'Mit echten Situationen aus dem Betrieb starten',
                        'text' => 'Bringen Sie Beispiele mit: Welche Anweisung wird missverstanden? Welche Rückfrage bleibt aus? Welche Dokumentation fällt schwer? Daraus lässt sich ein gezielter Sprachbedarf beschreiben. Anschließend klären wir Inhalte, organisatorischen Rahmen und Abstimmung. Vertrauliche Betriebsunterlagen oder persönliche Daten von Beschäftigten gehören nicht in die erste allgemeine Anfrage.'],
                ],
                'process' => ['eyebrow' => 'So geht es weiter', 'title' => 'In drei Schritten zum passenden Lernweg', 'steps' => [
                    ['title' => 'Situation beschreiben', 'text' => 'Nennen Sie Ihr Berufsziel oder eine typische Aufgabe, bei der Deutsch noch schwierig ist. Konkrete Beispiele helfen mehr als eine allgemeine Niveauangabe.'],
                    ['title' => 'Schwerpunkt finden', 'text' => 'Wir besprechen Ihre bisherigen Kenntnisse und wählen die wichtigsten Sprachsituationen aus. Vorhandene Zertifikate und Erfahrungen beziehen wir in die Planung ein.'],
                    ['title' => 'Lernweg abstimmen', 'text' => 'Gemeinsam klären wir Training, Lernform und mögliche ergänzende Begleitung. Für geförderte Sprachangebote werden Zugang und Zuständigkeit gesondert geprüft.'],
                ]],
                'modules_title' => 'Das gehört zu Sprache & Beruf',
                'faq' => [
                    ['q' => 'Reicht ein B1- oder B2-Zertifikat für jeden Beruf?', 'a' => 'Nicht pauschal. Welches Niveau erforderlich ist, hängt von Beruf, Betrieb und der Stelle ab, die den Nachweis verlangt. Zusätzlich zählt, ob Sie berufliche Situationen sprachlich sicher bewältigen – daran arbeiten wir im Training.'],
                    ['q' => 'Ist berufsbezogenes Sprachtraining ein BAMF-Kurs?', 'a' => 'Nein, nicht automatisch. BAMF-Berufssprachkurse folgen eigenen Teilnahme- und Zulassungsregeln. Den Status des konkreten Angebots klären wir mit Ihnen.'],
                    ['q' => 'Kann mein Arbeitgeber die Anfrage stellen?', 'a' => 'Ja. Unternehmen können mit Beispielen aus dem Arbeitsalltag anfragen. Vertrauliche Unterlagen oder persönliche Daten von Beschäftigten sind für die erste Anfrage nicht nötig.'],
                ],
                'cta_title' => 'Wer sich verständigen kann, kann mehr zeigen',
                'cta_text' => 'Beschreiben Sie uns eine Situation, in der Deutsch noch schwerfällt. Wir schlagen Ihnen den passenden Einstieg vor.',
            ],
            [
                'parent' => 'sprache-beruf', 'slug' => 'berufsdeutsch', 'title' => 'Berufsdeutsch', 'menu' => 'Berufsdeutsch', 'icon' => 'message-circle',
                'teaser' => 'Arbeitsaufträge, Rückfragen und Fachsprache üben', 'topic' => 'sprachkurs', 'cta' => 'Sprachtraining anfragen',
                'seo_title' => 'Berufsdeutsch: Sprachtraining für den Arbeitsalltag | MEA',
                'seo_desc' => 'Deutsch üben, das im Arbeitsalltag gebraucht wird: Arbeitsaufträge, Rückfragen, Übergaben und Fachsprache – berufsbegleitend, online oder am Wochenende.',
                'intro' => 'Eine Arbeitsanweisung verstehen, ein Problem beschreiben oder bei einer Übergabe nichts Wichtiges vergessen: Berufsdeutsch setzt an solchen Situationen an. Wir besprechen Ihren Lernbedarf und wählen passende Schwerpunkte. Für Gewerbe und Technik stehen konkrete Abläufe, Fachwörter und die Verständigung im Team besonders im Blick.',
                'highlights' => ['Typische Arbeitsgespräche und Aufgaben üben', 'Berufsbegleitende, Online- und Wochenendformate anfragen', 'Prüfungsvorbereitung passend zum Lernziel besprechen'],
                'sections' => [
                    ['eyebrow' => 'Trainingsinhalte', 'title' => 'Von einzelnen Wörtern zu verständlicher Kommunikation',
                        'text' => 'Fachwörter allein reichen nicht. Sie müssen Informationen verstehen, in eigenen Worten wiedergeben und bei Unklarheiten nachfragen können. Wir üben deshalb vollständige Situationen aus Ihrem Berufsalltag.',
                        'cards' => [
                            ['title' => 'Hören und nachfragen', 'text' => 'Arbeitsaufträge aufnehmen, wichtige Angaben erkennen und eine Rückfrage formulieren. Sprachübungen ersetzen keine betriebliche Sicherheitsunterweisung oder fachliche Einweisung.'],
                            ['title' => 'Lesen und schreiben', 'text' => 'Kurze Mitteilungen, Arbeitsinformationen oder einfache Dokumentationen verstehen und Informationen klar weitergeben.'],
                            ['title' => 'Sprechen und erklären', 'text' => 'Einen Ablauf beschreiben, ein Problem melden, einen Arbeitsschritt erläutern und auf Rückfragen reagieren.'],
                        ]],
                    ['eyebrow' => 'Lernformat', 'title' => 'Berufsbegleitend, online oder am Wochenende',
                        'text' => 'Berufsbegleitendes Sprachtraining sowie Online- und Wochenendkurse können Sie anfragen. Welches Format aktuell zu Ihrem Alltag passt, klären wir anhand Ihres Sprachstands, Ihres Ziels und Ihrer Zeit. Umfang, Ort und Ablauf stimmen wir für das konkrete Angebot ab.'],
                    ['eyebrow' => 'Prüfungsvorbereitung', 'title' => 'Auf Wunsch gezielt auf eine Prüfung hinarbeiten',
                        'text' => 'Welche Prüfung und welches Niveau Sie anstreben, besprechen wir vorab. Die Vorbereitung lässt sich mit berufsbegleitendem Lernen verbinden, soweit die Formate im gewünschten Zeitraum verfügbar sind. Die Prüfung selbst ist ein separates Angebot.'],
                ],
                'process' => ['eyebrow' => 'So geht es weiter', 'title' => 'In drei Schritten zum passenden Training', 'steps' => [
                    ['title' => 'Sprachsituationen sammeln', 'text' => 'Notieren Sie zwei oder drei Beispiele aus Arbeit oder Ausbildung, bei denen Verstehen, Sprechen, Lesen oder Schreiben noch schwerfällt.'],
                    ['title' => 'Lernziele auswählen', 'text' => 'Wir priorisieren die wichtigsten Situationen und beziehen vorhandene Kenntnisse ein.'],
                    ['title' => 'Rahmen vereinbaren', 'text' => 'Wir klären Lernform, Ort, Umfang und Kosten. Rückmeldungen aus dem Training fließen in die weiteren Lernschritte ein.'],
                ]],
                'faq' => [
                    ['q' => 'Brauche ich ein bestimmtes Sprachzertifikat?', 'a' => 'Für die Anfrage nicht. Ein Zertifikat kann Ihren Sprachstand einordnen; ob ein bestimmter Nachweis verlangt wird, klären Sie mit der Stelle, die ihn fordert.'],
                    ['q' => 'Gibt es berufsbegleitende oder Online-Angebote?', 'a' => 'Ja, diese Formate können Sie anfragen. Die aktuelle Verfügbarkeit bestätigen wir für Ihren Wunschzeitraum.'],
                    ['q' => 'Ist Einzeltraining ein BAMF-Berufssprachkurs?', 'a' => 'Nein, nicht automatisch. BAMF-Berufssprachkurse haben eigene Teilnahme- und Zulassungsregeln; wir klären den Status des Angebots mit Ihnen.'],
                ],
                'cta_title' => 'Welche Sprache brauchen Sie im Beruf?',
                'cta_text' => 'Schildern Sie uns typische Situationen aus Ihrem Alltag. Wir schlagen passende Schwerpunkte und ein Format vor.',
            ],
            [
                'parent' => 'sprache-beruf', 'slug' => 'unternehmen', 'title' => 'Sprachtraining für Unternehmen', 'menu' => 'Für Unternehmen', 'icon' => 'briefcase',
                'teaser' => 'Sprachtraining für den betrieblichen Alltag', 'topic' => 'sprachkurs', 'cta' => 'Betrieblichen Bedarf besprechen',
                'seo_title' => 'Sprachtraining für Unternehmen | MEA Sprache & Beruf',
                'seo_desc' => 'Deutsch für konkrete Arbeitssituationen, berufsbegleitendes Training und Prüfungsvorbereitung – abgestimmt auf Aufgaben und Abläufe Ihres Betriebs.',
                'intro' => 'Deutsch für konkrete Arbeitssituationen, berufsbegleitendes Training und Prüfungsvorbereitung: Wir klären Sprachziel, Format und nächsten Einstieg. Beschreiben Sie die konkreten Aufgaben und den Bedarf Ihres Betriebs – wir besprechen, welches Angebot dazu passt.',
                'highlights' => ['Ein Einstieg passend zu Ihrem Anliegen', 'Angebote dieses Bereichs persönlich klären', 'Ablauf und Kosten vor dem Beginn besprechen'],
                'sections' => [
                    ['eyebrow' => 'Unser Angebot', 'title' => 'Sprache dort üben, wo sie gebraucht wird',
                        'cards' => [
                            ['title' => 'Bedarf klären', 'text' => 'Wir betrachten gemeinsam, in welchen Situationen Verständigung im Betrieb wichtig ist und wo Rückfragen oder Missverständnisse entstehen.'],
                            ['title' => 'Training am Arbeitsalltag', 'text' => 'Inhalte entstehen aus Ihren Aufgaben, Abläufen und Fachbegriffen, damit das Gelernte direkt angewendet werden kann.'],
                            ['title' => 'Prüfungen für Mitarbeitende', 'text' => 'Für Unternehmen sind Gruppenprüfungen möglich. Möglichkeiten und Termine stimmen wir persönlich ab.'],
                        ]],
                    ['eyebrow' => 'Unsere Arbeitsweise', 'title' => 'Verständlich besprechen. Verbindlich abstimmen.',
                        'text' => 'Wir nehmen Ihr Ziel und Ihre Ausgangslage auf und erklären die nächsten Schritte in klaren Worten. Inhalte, Umfang, Ort und Kosten werden für das konkrete Angebot vor dem Einstieg geklärt. Wo eine andere Stelle über Förderung oder formale Voraussetzungen entscheidet, benennen wir diese Zuständigkeit und stimmen die erforderlichen Schritte ab.'],
                ],
                'faq' => [
                    ['q' => 'Wie beginne ich meine Anfrage?', 'a' => 'Beschreiben Sie zwei oder drei Situationen aus dem Betrieb, in denen Verständigung schwierig ist. Vertrauliche Unterlagen oder persönliche Daten von Beschäftigten sind dafür nicht nötig.'],
                    ['q' => 'Ist mit einer Anfrage schon ein Angebot vereinbart?', 'a' => 'Nein. Erst wenn Inhalte, Umfang, Ort und Kosten gemeinsam geklärt sind, entsteht ein Angebot.'],
                ],
                'cta_title' => 'Sprache & Beruf für Ihren betrieblichen Bedarf',
                'cta_text' => 'Schildern Sie uns Beispiele aus Ihrem Arbeitsalltag. Wir entwickeln daraus einen passenden Vorschlag.',
            ],
        ];
    }
}
