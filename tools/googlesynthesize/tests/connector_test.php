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

namespace aitool_googlesynthesize;

/**
 * Tests for the aitool_googlesynthesize connector.
 *
 * @package    aitool_googlesynthesize
 * @copyright  2026 ISB Bayern
 * @author     Thomas Schönlein
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aitool_googlesynthesize\connector
 */
final class connector_test extends \advanced_testcase {
    /**
     * Helper to invoke the protected get_endpoint_url() method.
     */
    private function call_get_endpoint_url(connector $connector): string {
        return (new \ReflectionMethod($connector, 'get_endpoint_url'))->invoke($connector);
    }

    /**
     * Creates a connector with a mocked instance returning the given endpoint value.
     */
    private function make_connector(string $endpoint): connector {
        $instance = $this->getMockBuilder(\local_ai_manager\base_instance::class)
            ->disableOriginalConstructor()
            ->getMock();
        $instance->method('get_endpoint')->willReturn($endpoint);
        return new connector($instance);
    }

    public function test_get_endpoint_url_returns_default_when_empty(): void {
        $this->assertEquals(
            'https://texttospeech.googleapis.com/v1/text:synthesize',
            $this->call_get_endpoint_url($this->make_connector(''))
        );
    }

    public function test_get_endpoint_url_returns_custom_when_set(): void {
        $customurl = 'https://my-proxy.example.com/v1/text:synthesize';
        $this->assertEquals(
            $customurl,
            $this->call_get_endpoint_url($this->make_connector($customurl))
        );
    }
}
