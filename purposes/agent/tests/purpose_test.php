<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace aipurpose_agent;

use aitool_chatgpt\instance;
use context_system;
use GuzzleHttp\Psr7\Stream;
use local_ai_manager\ai_manager_utils;
use local_ai_manager\local\config_manager;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\prompt_response;
use local_ai_manager\local\request_response;
use local_ai_manager\local\tenant;
use local_ai_manager\local\usage;
use local_ai_manager\local\userinfo;
use local_ai_manager\local\userusage;
use local_ai_manager\manager;
use local_ai_manager\plugininfo\aitool;
use stdClass;

/**
 * Unit tests for the agent purpose.
 *
 * @package    aipurpose_agent
 * @copyright  2025 ISB Bayern
 * @author     Andreas Wagner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aipurpose_agent\purpose
 */
final class purpose_test extends \advanced_testcase {
    /**
     * Tests the agent purpose perform request flow.
     *
     * @covers ::get_additional_request_options
     * @covers ::get_additional_purpose_options
     * @covers ::format_output
     */
    public function test_purpose_perform_request(): void {
        global $DB, $CFG;

        $this->resetAfterTest();

        $correctaichatsystemblock = new stdClass();
        $correctaichatsystemblock->blockname = 'ai_chat';
        $correctaichatsystemblock->parentcontextid = SYSCONTEXTID;
        $correctaichatsystemblock->showinsubcontexts = 0;
        $correctaichatsystemblock->requiredbytheme = 0;
        $correctaichatsystemblock->pagetypepattern = '';
        $correctaichatsystemblock->subpagepattern = '';
        $correctaichatsystemblock->defaultregion = '';
        $correctaichatsystemblock->defaultweight = '';
        $correctaichatsystemblock->configdata = '';
        $correctaichatsystemblock->timecreated = time();
        $correctaichatsystemblock->timemodified = $correctaichatsystemblock->timecreated;
        $correctaichatsystemblockid = $DB->insert_record('block_instances', $correctaichatsystemblock);
        $correctaichatsystemblockcontext = \context_block::instance($correctaichatsystemblockid);

        // Now also create user contexts.
        $user1 = $this->getDataGenerator()->create_user();

        // Setup the AI Manager.
        $this->setup_ai_manager($user1);
        $this->setUser($user1);
        $manager = new manager('chat');

        $conversationid = ai_manager_utils::get_next_free_itemid('block_ai_chat', $correctaichatsystemblockcontext->id);

        $options = file_get_contents($CFG->dirroot . '/local/ai_manager/purposes/agent/tests/fixtures/options.json');
        $agentoptions = [
            'agentoptions' => json_decode($options, true),
        ];

        $result = $manager->perform_request(
            'teacherinput',
            'block_ai_chat',
            $correctaichatsystemblockcontext->id,
            $agentoptions
        );

        $this->assertInstanceOf(prompt_response::class, $result);
    }

    /**
     * Data provider for testing formelement label formatting.
     *
     * @return array Test cases with input JSON and expected output checks
     */
    public static function format_output_formelement_label_provider(): array {
        return [
            'html_tags_stripped_from_label' => [
                'input' => json_encode([
                    'formelements' => [
                        [
                            'id' => 'id_test',
                            'name' => 'test',
                            'newValue' => 'Test value',
                            'label' => '<script>alert("xss")</script>Bold Label',
                            'explanation' => 'Simple explanation.',
                        ],
                    ],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => 'Intro'],
                        ['type' => 'outro', 'text' => 'Outro'],
                    ],
                ]),
                'expectedcontains' => 'Bold Label',
                'mustnotcontain' => '<script>',
            ],
            'html_in_label_removed' => [
                'input' => json_encode([
                    'formelements' => [
                        [
                            'id' => 'id_test',
                            'name' => 'test',
                            'newValue' => 'Value',
                            'label' => '<em>Italic Label</em>',
                            'explanation' => 'Explanation.',
                        ],
                    ],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => 'Intro'],
                        ['type' => 'outro', 'text' => 'Outro'],
                    ],
                ]),
                'expectedcontains' => 'Italic Label',
                'mustnotcontain' => '<em>',
            ],
            'plain_text_label' => [
                'input' => json_encode([
                    'formelements' => [
                        [
                            'id' => 'id_test',
                            'name' => 'test',
                            'newValue' => 'Value',
                            'label' => 'Plain Label',
                            'explanation' => 'Explanation.',
                        ],
                    ],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => 'Intro'],
                        ['type' => 'outro', 'text' => 'Outro'],
                    ],
                ]),
                'expectedcontains' => 'Plain Label',
                'mustnotcontain' => '',
            ],
            'markdown_in_label_not_formatted' => [
                'input' => json_encode([
                    'formelements' => [
                        [
                            'id' => 'id_test',
                            'name' => 'test',
                            'newValue' => 'Value',
                            'label' => '**Bold Label**',
                            'explanation' => 'Explanation.',
                        ],
                    ],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => 'Intro'],
                        ['type' => 'outro', 'text' => 'Outro'],
                    ],
                ]),
                'expectedcontains' => '**Bold Label**',
                'mustnotcontain' => '<strong>',
            ],
        ];
    }

    /**
     * Test that formelement labels have HTML stripped (not formatted with Markdown).
     *
     * @param string $input The JSON input
     * @param string $expectedcontains String that must be in the formatted label
     * @param string $mustnotcontain String that must not be in the formatted label
     *
     * @covers ::format_output
     * @dataProvider format_output_formelement_label_provider
     */
    public function test_format_output_formelement_label(string $input, string $expectedcontains, string $mustnotcontain): void {
        $purpose = new purpose();
        $output = $purpose->format_output($input);
        $decoded = json_decode($output, true);

        $this->assertNotNull($decoded, 'Output must be valid JSON');
        $this->assertArrayHasKey('formelements', $decoded);
        $this->assertNotEmpty($decoded['formelements']);
        $this->assertStringContainsString($expectedcontains, $decoded['formelements'][0]['label']);
        if (!empty($mustnotcontain)) {
            $this->assertStringNotContainsString($mustnotcontain, $decoded['formelements'][0]['label']);
        }
    }

    /**
     * Data provider for testing formelement explanation formatting.
     *
     * @return array Test cases with input JSON and expected output checks
     */
    public static function format_output_formelement_explanation_provider(): array {
        return [
            'italic_in_explanation' => [
                'input' => json_encode([
                    'formelements' => [
                        [
                            'id' => 'id_test',
                            'name' => 'test',
                            'newValue' => 'Value',
                            'label' => 'Label',
                            'explanation' => 'This is *italic* text.',
                        ],
                    ],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => 'Intro'],
                        ['type' => 'outro', 'text' => 'Outro'],
                    ],
                ]),
                'expectedcontains' => '<em>italic</em>',
            ],
            'bold_in_explanation' => [
                'input' => json_encode([
                    'formelements' => [
                        [
                            'id' => 'id_test',
                            'name' => 'test',
                            'newValue' => 'Value',
                            'label' => 'Label',
                            'explanation' => 'This is **bold** text.',
                        ],
                    ],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => 'Intro'],
                        ['type' => 'outro', 'text' => 'Outro'],
                    ],
                ]),
                'expectedcontains' => '<strong>bold</strong>',
            ],
            'link_in_explanation' => [
                'input' => json_encode([
                    'formelements' => [
                        [
                            'id' => 'id_test',
                            'name' => 'test',
                            'newValue' => 'Value',
                            'label' => 'Label',
                            'explanation' => 'See [docs](https://moodle.org) for info.',
                        ],
                    ],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => 'Intro'],
                        ['type' => 'outro', 'text' => 'Outro'],
                    ],
                ]),
                'expectedcontains' => 'href="https://moodle.org"',
            ],
        ];
    }

    /**
     * Test that formelement explanations are formatted with Markdown.
     *
     * @param string $input The JSON input
     * @param string $expectedcontains String that must be in the formatted explanation
     *
     * @covers ::format_output
     * @dataProvider format_output_formelement_explanation_provider
     */
    public function test_format_output_formelement_explanation(string $input, string $expectedcontains): void {
        $purpose = new purpose();
        $output = $purpose->format_output($input);
        $decoded = json_decode($output, true);

        $this->assertNotNull($decoded, 'Output must be valid JSON');
        $this->assertArrayHasKey('formelements', $decoded);
        $this->assertNotEmpty($decoded['formelements']);
        $this->assertStringContainsString($expectedcontains, $decoded['formelements'][0]['explanation']);
    }

    /**
     * Data provider for testing that newValue is preserved unchanged.
     *
     * @return array Test cases with input JSON and expected newValue
     */
    public static function format_output_newvalue_preserved_provider(): array {
        return [
            'html_tags_preserved' => [
                'input' => json_encode([
                    'formelements' => [
                        [
                            'id' => 'id_test',
                            'name' => 'test',
                            'newValue' => '<p style="color: red;">HTML content</p>',
                            'label' => 'Label',
                            'explanation' => 'Explanation.',
                        ],
                    ],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => 'Intro'],
                        ['type' => 'outro', 'text' => 'Outro'],
                    ],
                ]),
                'expectednewvalue' => '<p style="color: red;">HTML content</p>',
            ],
            'python_code_preserved' => [
                'input' => json_encode([
                    'formelements' => [
                        [
                            'id' => 'id_code',
                            'name' => 'code',
                            'newValue' => "def hello():\n    print('Hello')\n\n<html><body>Test</body></html>",
                            'label' => 'Code',
                            'explanation' => 'Python code.',
                        ],
                    ],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => 'Intro'],
                        ['type' => 'outro', 'text' => 'Outro'],
                    ],
                ]),
                'expectednewvalue' => "def hello():\n    print('Hello')\n\n<html><body>Test</body></html>",
            ],
            'script_tags_preserved' => [
                'input' => json_encode([
                    'formelements' => [
                        [
                            'id' => 'id_content',
                            'name' => 'content',
                            'newValue' => '<script>alert("test")</script><img onerror="evil()">',
                            'label' => 'Content',
                            'explanation' => 'Content field.',
                        ],
                    ],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => 'Intro'],
                        ['type' => 'outro', 'text' => 'Outro'],
                    ],
                ]),
                'expectednewvalue' => '<script>alert("test")</script><img onerror="evil()">',
            ],
            'full_html_document_preserved' => [
                'input' => json_encode([
                    'formelements' => [
                        [
                            'id' => 'id_summary',
                            'name' => 'summary',
                            'newValue' => '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"></head>'
                                . '<body><h1>Title</h1></body></html>',
                            'label' => 'Summary',
                            'explanation' => 'Full HTML.',
                        ],
                    ],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => 'Intro'],
                        ['type' => 'outro', 'text' => 'Outro'],
                    ],
                ]),
                'expectednewvalue' => '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"></head>'
                    . '<body><h1>Title</h1></body></html>',
            ],
            'plain_text_preserved' => [
                'input' => json_encode([
                    'formelements' => [
                        [
                            'id' => 'id_name',
                            'name' => 'name',
                            'newValue' => 'Simple course name',
                            'label' => 'Name',
                            'explanation' => 'Course name.',
                        ],
                    ],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => 'Intro'],
                        ['type' => 'outro', 'text' => 'Outro'],
                    ],
                ]),
                'expectednewvalue' => 'Simple course name',
            ],
            'multiline_text_preserved' => [
                'input' => json_encode([
                    'formelements' => [
                        [
                            'id' => 'id_desc',
                            'name' => 'desc',
                            'newValue' => "Line 1\nLine 2\n\nLine 4",
                            'label' => 'Description',
                            'explanation' => 'Multiline.',
                        ],
                    ],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => 'Intro'],
                        ['type' => 'outro', 'text' => 'Outro'],
                    ],
                ]),
                'expectednewvalue' => "Line 1\nLine 2\n\nLine 4",
            ],
        ];
    }

    /**
     * Test that newValue in formelements is preserved unchanged (not formatted).
     *
     * The newValue must be preserved as-is because it will be injected into form fields.
     * Any formatting/escaping happens at display time in the Mustache template.
     *
     * @param string $input The JSON input
     * @param string $expectednewvalue The expected unchanged newValue
     *
     * @covers ::format_output
     * @dataProvider format_output_newvalue_preserved_provider
     */
    public function test_format_output_newvalue_preserved(string $input, string $expectednewvalue): void {
        $purpose = new purpose();
        $output = $purpose->format_output($input);
        $decoded = json_decode($output, true);

        $this->assertNotNull($decoded, 'Output must be valid JSON');
        $this->assertArrayHasKey('formelements', $decoded);
        $this->assertNotEmpty($decoded['formelements']);
        $this->assertEquals(
            $expectednewvalue,
            $decoded['formelements'][0]['newValue'],
            'newValue must be preserved exactly as provided'
        );
    }

    /**
     * Data provider for testing chatoutput intro and outro formatting.
     *
     * @return array Test cases with text and expected output checks
     */
    public static function format_output_chatoutput_text_provider(): array {
        $codeblock = "\x60\x60\x60";

        return [
            'bold_and_italic' => [
                'text' => 'Here is **bold** and *italic* text.',
                'mustcontain' => ['<strong>bold</strong>', '<em>italic</em>'],
                'mustnotcontain' => [],
            ],
            'ordered_list' => [
                'text' => 'Steps:' . PHP_EOL . PHP_EOL . '1. First' . PHP_EOL . '2. Second' . PHP_EOL . '3. Third',
                'mustcontain' => ['<ol>', '<li>'],
                'mustnotcontain' => [],
            ],
            'unordered_list' => [
                'text' => 'Items:' . PHP_EOL . PHP_EOL . '- Item A' . PHP_EOL . '- Item B' . PHP_EOL . '- Item C',
                'mustcontain' => ['<ul>', '<li>'],
                'mustnotcontain' => [],
            ],
            'heading' => [
                'text' => '## Configuration Complete' . PHP_EOL . PHP_EOL . 'All done.',
                'mustcontain' => ['<h2>'],
                'mustnotcontain' => [],
            ],
            'code_block_html_escaped' => [
                'text' => 'Example:' . PHP_EOL . PHP_EOL
                    . $codeblock . 'python' . PHP_EOL
                    . 'print("<html>")' . PHP_EOL
                    . $codeblock,
                'mustcontain' => ['<pre>', '<code', '&lt;html&gt;'],
                'mustnotcontain' => ['<html>'],
            ],
            'script_tag_sanitized' => [
                'text' => 'Hello <script>alert("xss")</script> world',
                'mustcontain' => ['Hello', 'world'],
                'mustnotcontain' => ['<script>', 'alert('],
            ],
            'link_formatting' => [
                'text' => 'Visit [Moodle](https://moodle.org) for more.',
                'mustcontain' => ['href="https://moodle.org"', '>Moodle</a>'],
                'mustnotcontain' => [],
            ],
            'bold_text' => [
                'text' => 'This is **important**.',
                'mustcontain' => ['<strong>important</strong>'],
                'mustnotcontain' => [],
            ],
        ];
    }

    /**
     * Test that chatoutput intro and outro text is properly formatted with Markdown.
     *
     * Both intro and outro are handled identically in the code, so this tests both.
     *
     * @param string $text The text to test
     * @param array $mustcontain Strings that must be in the formatted output
     * @param array $mustnotcontain Strings that must not be in the formatted output
     *
     * @covers ::format_output
     * @dataProvider format_output_chatoutput_text_provider
     */
    public function test_format_output_chatoutput_text(string $text, array $mustcontain, array $mustnotcontain): void {
        $purpose = new purpose();

        // Test with intro.
        $inputintro = json_encode([
            'formelements' => [],
            'chatoutput' => [
                ['type' => 'intro', 'text' => $text],
                ['type' => 'outro', 'text' => ''],
            ],
        ]);
        $outputintro = $purpose->format_output($inputintro);
        $decodedintro = json_decode($outputintro, true);

        $this->assertNotNull($decodedintro, 'Output must be valid JSON');
        $intro = '';
        foreach ($decodedintro['chatoutput'] as $item) {
            if ($item['type'] === 'intro') {
                $intro = $item['text'];
                break;
            }
        }

        foreach ($mustcontain as $expected) {
            $this->assertStringContainsString($expected, $intro, "Intro must contain: $expected");
        }
        foreach ($mustnotcontain as $notexpected) {
            $this->assertStringNotContainsString($notexpected, $intro, "Intro must not contain: $notexpected");
        }

        // Test with outro.
        $inputoutro = json_encode([
            'formelements' => [],
            'chatoutput' => [
                ['type' => 'intro', 'text' => ''],
                ['type' => 'outro', 'text' => $text],
            ],
        ]);
        $outputoutro = $purpose->format_output($inputoutro);
        $decodedoutro = json_decode($outputoutro, true);

        $this->assertNotNull($decodedoutro, 'Output must be valid JSON');
        $outro = '';
        foreach ($decodedoutro['chatoutput'] as $item) {
            if ($item['type'] === 'outro') {
                $outro = $item['text'];
                break;
            }
        }

        foreach ($mustcontain as $expected) {
            $this->assertStringContainsString($expected, $outro, "Outro must contain: $expected");
        }
        foreach ($mustnotcontain as $notexpected) {
            $this->assertStringNotContainsString($notexpected, $outro, "Outro must not contain: $notexpected");
        }
    }

    /**
     * Data provider for invalid input handling tests.
     *
     * @return array Test cases with invalid inputs
     */
    public static function format_output_invalid_input_provider(): array {
        return [
            'non_json_string' => [
                'input' => 'This is not JSON at all',
            ],
            'empty_string' => [
                'input' => '',
            ],
            'malformed_json' => [
                'input' => '{"formelements": [}',
            ],
            'plain_text_response' => [
                'input' => 'The AI returned plain text instead of JSON.',
            ],
            'array_instead_of_object' => [
                'input' => '["item1", "item2"]',
            ],
        ];
    }

    /**
     * Test that format_output returns valid structure even for invalid JSON input.
     *
     * @param string $input The invalid input
     *
     * @covers ::format_output
     * @dataProvider format_output_invalid_input_provider
     */
    public function test_format_output_invalid_input(string $input): void {
        $purpose = new purpose();
        $output = $purpose->format_output($input);
        $decoded = json_decode($output, true);

        $this->assertNotNull($decoded, 'Output must be valid JSON even for invalid input');
        $this->assertArrayHasKey('formelements', $decoded, 'Output must contain formelements key');
        $this->assertArrayHasKey('chatoutput', $decoded, 'Output must contain chatoutput key');
    }

    /**
     * Data provider for missing required fields tests.
     *
     * @return array Test cases with missing fields
     */
    public static function format_output_missing_fields_provider(): array {
        return [
            'missing_chatoutput' => [
                'input' => json_encode([
                    'formelements' => [
                        ['id' => 'test', 'newValue' => 'value', 'label' => 'Test', 'explanation' => 'Explanation'],
                    ],
                ]),
            ],
            'missing_formelements' => [
                'input' => json_encode([
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => 'Intro'],
                        ['type' => 'outro', 'text' => 'Outro'],
                    ],
                ]),
            ],
            'empty_json_object' => [
                'input' => '{}',
            ],
            'null_formelements' => [
                'input' => json_encode([
                    'formelements' => null,
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => 'Intro'],
                        ['type' => 'outro', 'text' => 'Outro'],
                    ],
                ]),
            ],
        ];
    }

    /**
     * Test that format_output handles missing required fields gracefully.
     *
     * @param string $input The JSON input with missing fields
     *
     * @covers ::format_output
     * @dataProvider format_output_missing_fields_provider
     */
    public function test_format_output_missing_fields(string $input): void {
        $purpose = new purpose();
        $output = $purpose->format_output($input);
        $decoded = json_decode($output, true);

        $this->assertNotNull($decoded, 'Output must be valid JSON');
        $this->assertArrayHasKey('formelements', $decoded, 'Output must contain formelements key');
        $this->assertArrayHasKey('chatoutput', $decoded, 'Output must contain chatoutput key');
    }

    /**
     * Test that multiple formelements are all processed correctly.
     *
     * @covers ::format_output
     */
    public function test_format_output_multiple_formelements(): void {
        $input = json_encode([
            'formelements' => [
                [
                    'id' => 'id_first',
                    'name' => 'first',
                    'newValue' => '<div>First value</div>',
                    'label' => '**First Field**',
                    'explanation' => 'First *explanation*.',
                ],
                [
                    'id' => 'id_second',
                    'name' => 'second',
                    'newValue' => '<script>second</script>',
                    'label' => '__Second Field__',
                    'explanation' => 'Second **explanation**.',
                ],
                [
                    'id' => 'id_third',
                    'name' => 'third',
                    'newValue' => 'Plain text value',
                    'label' => 'Third Field',
                    'explanation' => 'Third [link](https://example.com).',
                ],
            ],
            'chatoutput' => [
                ['type' => 'intro', 'text' => 'Multiple fields.'],
                ['type' => 'outro', 'text' => 'Done.'],
            ],
        ]);

        $purpose = new purpose();
        $output = $purpose->format_output($input);
        $decoded = json_decode($output, true);

        $this->assertNotNull($decoded);
        $this->assertCount(3, $decoded['formelements'], 'Should have 3 formelements');

        // Verify first element - label should have markdown stripped, not formatted.
        $this->assertStringContainsString('**First Field**', $decoded['formelements'][0]['label']);
        $this->assertStringNotContainsString('<strong>', $decoded['formelements'][0]['label']);
        $this->assertStringContainsString('<em>explanation</em>', $decoded['formelements'][0]['explanation']);
        $this->assertEquals('<div>First value</div>', $decoded['formelements'][0]['newValue']);

        // Verify second element.
        $this->assertStringContainsString('__Second Field__', $decoded['formelements'][1]['label']);
        $this->assertStringContainsString('<strong>explanation</strong>', $decoded['formelements'][1]['explanation']);
        $this->assertEquals('<script>second</script>', $decoded['formelements'][1]['newValue']);

        // Verify third element.
        $this->assertStringContainsString('href="https://example.com"', $decoded['formelements'][2]['explanation']);
        $this->assertEquals('Plain text value', $decoded['formelements'][2]['newValue']);
    }

    /**
     * Test that empty formelements array is handled correctly.
     *
     * @covers ::format_output
     */
    public function test_format_output_empty_formelements(): void {
        $input = json_encode([
            'formelements' => [],
            'chatoutput' => [
                ['type' => 'intro', 'text' => 'No suggestions.'],
                ['type' => 'outro', 'text' => 'Provide more details.'],
            ],
        ]);

        $purpose = new purpose();
        $output = $purpose->format_output($input);
        $decoded = json_decode($output, true);

        $this->assertNotNull($decoded);
        $this->assertArrayHasKey('formelements', $decoded);
        $this->assertEmpty($decoded['formelements']);
        $this->assertArrayHasKey('chatoutput', $decoded);
        $this->assertNotEmpty($decoded['chatoutput']);
    }

    /**
     * Test format_output with the actual fixture response file.
     *
     * @covers ::format_output
     */
    public function test_format_output_with_fixture_response(): void {
        global $CFG;

        $fixturepath = $CFG->dirroot . '/local/ai_manager/purposes/agent/tests/fixtures/response.txt';

        $input = file_get_contents($fixturepath);
        $purpose = new purpose();
        $output = $purpose->format_output($input);
        $decoded = json_decode($output, true);

        $this->assertNotNull($decoded, 'Output must be valid JSON');
        $this->assertArrayHasKey('formelements', $decoded);
        $this->assertArrayHasKey('chatoutput', $decoded);
        $this->assertNotEmpty($decoded['formelements'], 'Fixture should have formelements');
        $this->assertNotEmpty($decoded['chatoutput'], 'Fixture should have chatoutput');

        // Verify structure of chatoutput.
        $hasintro = false;
        $hasoutro = false;
        foreach ($decoded['chatoutput'] as $item) {
            if ($item['type'] === 'intro') {
                $hasintro = true;
                $this->assertNotEmpty($item['text'], 'Intro text should not be empty');
            }
            if ($item['type'] === 'outro') {
                $hasoutro = true;
            }
        }
        $this->assertTrue($hasintro, 'Chatoutput must have intro');
        $this->assertTrue($hasoutro, 'Chatoutput must have outro');
    }

    /**
     * Test validate_chatoutput returns proper structure.
     *
     * @covers ::format_output
     */
    public function test_format_output_chatoutput_structure(): void {
        $input = json_encode([
            'formelements' => [],
            'chatoutput' => [
                ['type' => 'intro', 'text' => 'Intro text here.'],
                ['type' => 'outro', 'text' => 'Outro text here.'],
                ['type' => 'unknown', 'text' => 'This should be ignored.'],
            ],
        ]);

        $purpose = new purpose();
        $output = $purpose->format_output($input);
        $decoded = json_decode($output, true);

        $this->assertNotNull($decoded);
        $this->assertCount(2, $decoded['chatoutput'], 'Should only have intro and outro');

        $types = array_column($decoded['chatoutput'], 'type');
        $this->assertContains('intro', $types);
        $this->assertContains('outro', $types);
        $this->assertNotContains('unknown', $types);
    }

    /**
     * Test that JSON with extra text around it is handled correctly.
     *
     * @covers ::format_output
     */
    public function test_format_output_json_with_surrounding_text(): void {
        $input = 'Here is the JSON response: {"formelements": [], "chatoutput": [{"type": "intro", "text": "Test"}, '
            . '{"type": "outro", "text": ""}]} End of response.';

        $purpose = new purpose();
        $output = $purpose->format_output($input);
        $decoded = json_decode($output, true);

        $this->assertNotNull($decoded, 'Should extract JSON from surrounding text');
        $this->assertArrayHasKey('formelements', $decoded);
        $this->assertArrayHasKey('chatoutput', $decoded);
    }

    /**
     * Helper function to set up AI manager for testing.
     *
     * @param stdClass $user The user to set up
     */
    private function setup_ai_manager(stdClass $user): void {
        global $DB, $CFG;

        $tenant = new tenant('1234');
        $systemcontext = context_system::instance();
        $aiuserrole = $DB->get_record('role', ['shortname' => 'aiuser']);
        if (empty($aiuserrole)) {
            $this->getDataGenerator()->create_role(['shortname' => 'aiuser']);
            $aiuserrole = $DB->get_record('role', ['shortname' => 'aiuser']);
        }
        role_assign($aiuserrole->id, $user->id, $systemcontext->id);
        assign_capability('local/ai_manager:use', CAP_ALLOW, $aiuserrole->id, $systemcontext->id);

        $configmanager = new config_manager($tenant);
        $configmanager->set_config('tenantenabled', 1);

        $userinfo = new userinfo($user->id);
        $userinfo->set_locked(false);
        $userinfo->set_confirmed(true);
        $userinfo->set_scope(userinfo::SCOPE_EVERYWHERE);
        $userinfo->set_role(userinfo::ROLE_BASIC);
        $userinfo->store();

        $configmanager->set_config('chat_max_requests_basic', 1000);

        $userusage = new userusage(\core\di::get(connector_factory::class)->get_purpose_by_purpose_string('chat'), $user->id);
        $userusage->set_currentusage(0);
        $userusage->store();

        // Setup the AI Manager.
        $chatgptinstance = new instance();
        $chatgptinstance->set_model('gpt-4o');
        $chatgptinstance->set_connector('chatgpt');

        // Fake a stream object, because we will mock the method that accesses it anyway.
        $streamresponse = new Stream(fopen('php://temp', 'r+'));
        $requestresponse = request_response::create_from_result($streamresponse);

        // Fake usage object.
        $usage = new usage(50.0, 30.0, 20.0);

        // Fake prompt_response object.
        $responsetext = file_get_contents($CFG->dirroot . '/local/ai_manager/purposes/agent/tests/fixtures/response.txt');

        $promptresponse = prompt_response::create_from_result('gpt-4o', $usage, $responsetext);

        $chatgptconnector =
            $this->getMockBuilder('\aitool_chatgpt\connector')->setConstructorArgs([$chatgptinstance])->getMock();
        $chatgptconnector->expects($this->any())->method('make_request')->willReturn($requestresponse);
        $chatgptconnector->expects($this->any())->method('execute_prompt_completion')->willReturn($promptresponse);
        $connectorfactory =
            $this->getMockBuilder(connector_factory::class)->setConstructorArgs([$configmanager])->getMock();
        $connectorfactory->expects($this->any())->method('get_connector_by_purpose')->willReturn($chatgptconnector);
        $connectorfactory->expects($this->any())->method('get_connector_instance_by_purpose')->willReturn($chatgptinstance);

        $chatpurpose = new purpose();
        $connectorfactory->expects($this->any())->method('get_purpose_by_purpose_string')->willReturn($chatpurpose);
        \core\di::set(config_manager::class, $configmanager);
        \core\di::set(connector_factory::class, $connectorfactory);

        // We enable the aitool plugin here.
        aitool::enable_plugin('agent', true);

        // We disable the hook here, so no other plugin is interfering.
        $this->redirectHook(\local_ai_manager\hook\additional_user_restriction::class, fn() => null);
    }

    /**
     * Build a minimal valid LLM JSON response string.
     *
     * @param string $introtext The intro text for the chatoutput.
     * @param string $outrotext The outro text for the chatoutput.
     * @param array $formelements Optional form elements array.
     * @return string JSON-encoded string as it would come from the LLM.
     */
    private function build_llm_response(string $introtext, string $outrotext = '', array $formelements = []): string {
        return json_encode([
            'formelements' => $formelements,
            'chatoutput' => [
                ['type' => 'intro', 'text' => $introtext],
                ['type' => 'outro', 'text' => $outrotext],
            ],
        ]);
    }

    /**
     * Data provider for test_format_output.
     *
     * Each case provides intro text, outro text, expected substrings that MUST be present
     * in the intro HTML, and expected substrings that MUST NOT be present.
     *
     * @return array array containing the different test cases
     */
    public static function format_output_provider(): array {
        return [
            'plain_text_without_newlines' => [
                'intro' => 'Simple response without any newlines.',
                'outro' => '',
                'introcontains' => ['<p>Simple response without any newlines.</p>'],
                'intronotcontains' => [],
            ],
            'single_newline_produces_separate_paragraphs' => [
                'intro' => "a\nb",
                'outro' => '',
                'introcontains' => ['<p>a</p>', '<p>b</p>'],
                'intronotcontains' => [],
            ],
            'double_newline_stays_as_paragraph_break' => [
                'intro' => "a\n\nb",
                'outro' => '',
                'introcontains' => ['<p>a</p>', '<p>b</p>'],
                'intronotcontains' => ['<p></p>'],
            ],
            'triple_newline_is_not_inflated_further' => [
                'intro' => "a\n\n\nb",
                'outro' => '',
                'introcontains' => ['<p>a</p>', '<p>b</p>'],
                'intronotcontains' => [],
            ],
            'unordered_list_with_single_newlines_renders_as_ul' => [
                'intro' => "Suggestions:\n- Item one.\n- Item two.\n- Item three.",
                'outro' => '',
                'introcontains' => ['<ul>', '<li>', 'Item one.', 'Item two.', 'Item three.'],
                'intronotcontains' => [],
            ],
            'unordered_list_with_double_newlines_renders_as_ul' => [
                'intro' => "Suggestions:\n\n- Alpha.\n\n- Beta.\n\n- Gamma.",
                'outro' => '',
                'introcontains' => ['<ul>', '<li>', 'Alpha.', 'Beta.', 'Gamma.'],
                'intronotcontains' => [],
            ],
            'numbered_items_with_paren_become_separate_paragraphs' => [
                'intro' => "Changes:\n1) First.\n2) Second.\n3) Third.",
                'outro' => '',
                // PHP Markdown Extra does not support "1)" as list syntax, so each item becomes its own <p>.
                'introcontains' => ['<p>1) First.</p>', '<p>2) Second.</p>', '<p>3) Third.</p>'],
                'intronotcontains' => [],
            ],
            'ordered_list_with_dot_syntax_renders_as_ol' => [
                'intro' => "Steps:\n1. First step.\n2. Second step.\n3. Third step.",
                'outro' => '',
                'introcontains' => ['<ol>', '<li>', 'First step.', 'Second step.'],
                'intronotcontains' => [],
            ],
            'mixed_paragraphs_numbered_items_and_unordered_list' => [
                'intro' => "Ich schlage vor, folgende Schritte durchzuführen:\n\n"
                    . "1) Kurzname bereinigen.\n"
                    . "2) Kurszusammenfassung hinzufügen.\n\n"
                    . "Einstellungen, die bereits sinnvoll gesetzt sind:\n"
                    . "- Course visibility: Show.\n"
                    . "- AI Chat: aktiviert.\n\n"
                    . "Sag mir bitte, welche Änderungen ich vornehmen soll.",
                'outro' => '',
                'introcontains' => [
                    '<ul>',
                    '<li>',
                    'Course visibility: Show.',
                    '<p>1) Kurzname bereinigen.</p>',
                    '<p>2) Kurszusammenfassung hinzufügen.</p>',
                ],
                'intronotcontains' => [],
            ],
            'outro_list_is_also_normalized' => [
                'intro' => 'Intro.',
                'outro' => "Fragen:\n- Kursstart anpassen?\n- Gruppen verwenden?",
                'introcontains' => ['<p>Intro.</p>'],
                'intronotcontains' => [],
            ],
        ];
    }

    /**
     * Test chatoutput rendering through format_output with various inputs.
     *
     * @dataProvider format_output_provider
     * @covers \aipurpose_agent\purpose::format_output
     * @param string $intro The intro text for the LLM response.
     * @param string $outro The outro text for the LLM response.
     * @param array $introcontains Substrings that must be present in the rendered intro HTML.
     * @param array $intronotcontains Substrings that must not be present in the rendered intro HTML.
     */
    public function test_format_output(
        string $intro,
        string $outro,
        array $introcontains,
        array $intronotcontains,
    ): void {
        $purpose = new purpose();
        $result = $purpose->format_output($this->build_llm_response($intro, $outro));

        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded, 'format_output must return valid JSON');
        $this->assertArrayHasKey('chatoutput', $decoded);

        $texts = [];
        foreach ($decoded['chatoutput'] as $entry) {
            $texts[$entry['type']] = $entry['text'];
        }

        foreach ($introcontains as $expected) {
            $this->assertStringContainsString($expected, $texts['intro']);
        }
        foreach ($intronotcontains as $notexpected) {
            $this->assertStringNotContainsString($notexpected, $texts['intro']);
        }

        // For cases with an outro containing list markers, verify the outro HTML too.
        if (!empty($outro) && str_contains($outro, "\n-")) {
            $this->assertStringContainsString('<ul>', $texts['outro']);
            $this->assertStringContainsString('<li>', $texts['outro']);
        }
    }

    /**
     * Test that formelements are passed through correctly alongside the chatoutput.
     *
     * @covers \aipurpose_agent\purpose::format_output
     */
    public function test_format_output_preserves_formelements(): void {
        $this->resetAfterTest();

        $formelements = [
            [
                'id' => 'id_name',
                'name' => 'name',
                'newValue' => 'My Course',
                'explanation' => 'A descriptive name.',
            ],
        ];

        $purpose = new purpose();
        $result = $purpose->format_output(
            $this->build_llm_response("Vorschläge:\n- Kursname anpassen.", '', $formelements)
        );
        $decoded = json_decode($result, true);

        $this->assertNotEmpty($decoded['formelements']);
        $this->assertEquals('id_name', $decoded['formelements'][0]['id']);
        $this->assertStringContainsString('<ul>', $decoded['chatoutput'][0]['text']);
    }

    /**
     * Data provider for error and edge-case handling in format_output.
     *
     * Each case provides the raw input string and the expected decoded output array.
     *
     * @return array
     */
    public static function error_output_provider(): array {
        // The error output returned when JSON is valid does not have the correct structure.
        $erroroutput = [
            'formelements' => [],
            'chatoutput' => [
                ['type' => 'intro', 'text' => get_string('error_unusuableresponse', 'aipurpose_agent')],
                ['type' => 'outro', 'text' => ''],
            ],
        ];

        return [
            'no_json_found_in_plain_text' => [
                'input' => 'This is plain text without any JSON.',
                'expected' => [
                    'formelements' => [],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => "<p>This is plain text without any JSON.</p>\n"],
                        ['type' => 'outro', 'text' => ''],
                    ],
                ],
            ],
            'invalid_json_syntax' => [
                'input' => '{invalid json: content here}',
                'expected' => [
                    'formelements' => [],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => "<p>{invalid json: content here}</p>\n"],
                        ['type' => 'outro', 'text' => ''],
                    ],
                ],
            ],
            'empty_json_object' => [
                'input' => '{}',
                'expected' => [
                    'formelements' => [],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => "<p>{}</p>\n"],
                        ['type' => 'outro', 'text' => ''],
                    ],
                ],
            ],
            'json_array_instead_of_object' => [
                'input' => '[1, 2, 3]',
                'expected' => [
                    'formelements' => [],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => "<p>[1, 2, 3]</p>\n"],
                        ['type' => 'outro', 'text' => ''],
                    ],
                ],
            ],
            'missing_formelements_key' => [
                'input' => json_encode([
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => 'Hello.'],
                        ['type' => 'outro', 'text' => ''],
                    ],
                ]),
                'expected' => $erroroutput,
            ],
            'missing_chatoutput_key' => [
                'input' => json_encode([
                    'formelements' => [],
                ]),
                'expected' => $erroroutput,
            ],
            'missing_both_required_keys' => [
                'input' => '{"somekey": "somevalue"}',
                'expected' => $erroroutput,
            ],
        ];
    }

    /**
     * Test error and edge-case handling in format_output.
     *
     * @dataProvider error_output_provider
     * @covers \aipurpose_agent\purpose::format_output
     * @param string $input The raw input string passed to format_output.
     * @param array $expected The full expected output array.
     */
    public function test_format_output_error_and_edge_cases(string $input, array $expected): void {
        $purpose = new purpose();
        $result = $purpose->format_output($input);

        $this->assertJsonStringEqualsJsonString(json_encode($expected), $result);
    }
}
