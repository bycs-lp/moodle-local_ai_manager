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

namespace local_ai_manager;

use local_ai_content\local\enriched_vector;
use local_ai_manager\tests\testable_vecstore;

/**
 * Tests for the backend independent collection handling of vector stores: one collection per embedding model.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(base_vecstore::class)]
final class base_vecstore_test extends \advanced_testcase {
    /**
     * Create an in-memory vector store backed by a stored instance.
     *
     * @param string $prefix The collection name prefix of the instance.
     * @return testable_vecstore The vector store.
     */
    private function create_vecstore(string $prefix = 'rag'): testable_vecstore {
        $instance = new base_vecstore_instance();
        $instance->set_name('Test');
        $instance->set_vecstore('qdrant');
        $instance->set_collection($prefix);
        $instance->store();
        return new testable_vecstore($instance);
    }

    /**
     * Create an enriched vector of a source.
     *
     * @param int $sourceid The source id.
     * @param float[] $vector The vector.
     * @return enriched_vector The enriched vector.
     */
    private function create_vector(int $sourceid, array $vector): enriched_vector {
        return enriched_vector::create(json_encode($vector), 'content ' . $sourceid, $sourceid, 0, 1);
    }

    /**
     * Every embedding model gets its own collection, created on the first insert with the dimensionality of its vectors.
     */
    public function test_insert_embeddings_creates_collection_per_model(): void {
        global $DB;
        $this->resetAfterTest();

        $vecstore = $this->create_vecstore();
        $this->assertSame(200, $vecstore->insert_embeddings('Model-A/1', [$this->create_vector(1, [0.1, 0.2, 0.3])])->get_code());
        $this->assertSame(200, $vecstore->insert_embeddings('model-b', [$this->create_vector(1, [0.1, 0.2])])->get_code());

        $this->assertSame(['Model-A/1' => 'rag_model_a_1', 'model-b' => 'rag_model_b'], $vecstore->get_collections());
        $this->assertSame(3, (int) $DB->get_field('local_ai_manager_collection', 'dimensions', ['embeddingmodel' => 'Model-A/1']));
        $this->assertSame(2, $vecstore->collections['rag_model_b']['dimensions']);
        $this->assertCount(1, $vecstore->collections['rag_model_a_1']['vectors']);
    }

    /**
     * Inserting replaces the vectors of the source in the collection of the model only.
     */
    public function test_insert_embeddings_replaces_vectors_of_model_only(): void {
        $this->resetAfterTest();

        $vecstore = $this->create_vecstore();
        $vecstore->insert_embeddings('model-a', [$this->create_vector(1, [0.1, 0.2])]);
        $vecstore->insert_embeddings('model-b', [$this->create_vector(1, [0.1, 0.2])]);
        $vecstore->insert_embeddings('model-a', [$this->create_vector(1, [0.3, 0.4]), $this->create_vector(1, [0.5, 0.6])]);

        $this->assertCount(2, $vecstore->collections['rag_model_a']['vectors']);
        $this->assertCount(1, $vecstore->collections['rag_model_b']['vectors']);
    }

    /**
     * A query only searches the collection of the given model, a model without collection has no vectors.
     */
    public function test_query_searches_collection_of_model(): void {
        $this->resetAfterTest();

        $vecstore = $this->create_vecstore();
        $vecstore->insert_embeddings('model-a', [$this->create_vector(1, [0.1, 0.2])]);
        $vecstore->insert_embeddings('model-b', [$this->create_vector(2, [0.1, 0.2])]);

        $matches = $vecstore->query('model-b', [0.1, 0.2])->get_queryresponse()->get_matches();
        $this->assertCount(1, $matches);
        $this->assertSame(2, $matches[0]->get_sourceid());

        $response = $vecstore->query('model-c', [0.1, 0.2]);
        $this->assertSame(200, $response->get_code());
        $this->assertSame([], $response->get_queryresponse()->get_matches());
    }

    /**
     * Vectors not fitting the dimensionality of the collection of their model are rejected.
     */
    public function test_dimension_mismatch_is_rejected(): void {
        $this->resetAfterTest();

        $vecstore = $this->create_vecstore();
        $vecstore->insert_embeddings('model-a', [$this->create_vector(1, [0.1, 0.2, 0.3])]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('exception_vecstoredimensionmismatch', 'local_ai_manager',
            ['model' => 'model-a', 'modeldimensions' => 2, 'vecstoredimensions' => 3]));
        $vecstore->query('model-a', [0.1, 0.2]);
    }

    /**
     * Deleting embeddings affects the collection of the given model or the collections of all models.
     */
    public function test_delete_embeddings(): void {
        $this->resetAfterTest();

        $vecstore = $this->create_vecstore();
        foreach (['model-a', 'model-b'] as $model) {
            $vecstore->insert_embeddings($model, [$this->create_vector(1, [0.1, 0.2])]);
            $vecstore->insert_embeddings($model, [$this->create_vector(2, [0.1, 0.2])]);
        }

        $this->assertSame(200, $vecstore->delete_embeddings('model-a', 1)->get_code());
        $this->assertCount(1, $vecstore->collections['rag_model_a']['vectors']);
        $this->assertCount(2, $vecstore->collections['rag_model_b']['vectors']);

        $this->assertSame(200, $vecstore->delete_embeddings(null, 2)->get_code());
        $this->assertCount(0, $vecstore->collections['rag_model_a']['vectors']);
        $this->assertCount(1, $vecstore->collections['rag_model_b']['vectors']);
    }

    /**
     * Dropping the collection of a model removes it from the backend and from the bookkeeping.
     */
    public function test_drop_collection(): void {
        $this->resetAfterTest();

        $vecstore = $this->create_vecstore();
        $vecstore->insert_embeddings('model-a', [$this->create_vector(1, [0.1, 0.2])]);
        $vecstore->insert_embeddings('model-b', [$this->create_vector(1, [0.1, 0.2])]);

        $this->assertSame(200, $vecstore->drop_collection('model-a')->get_code());

        $this->assertSame(['model-b' => 'rag_model_b'], $vecstore->get_collections());
        $this->assertArrayNotHasKey('rag_model_a', $vecstore->collections);
    }

    /**
     * Collection names exceeding the maximum length are shortened and kept unique by a hash of the model name.
     */
    public function test_build_collection_name_shortens_long_names(): void {
        $this->resetAfterTest();

        $vecstore = $this->create_vecstore('prefix');
        $longmodel = str_repeat('a', 80);
        $name = $vecstore->build_collection_name_for_test($longmodel);

        $this->assertSame(base_vecstore::MAX_COLLECTION_NAME_LENGTH, strlen($name));
        $this->assertStringEndsWith('_' . substr(sha1($longmodel), 0, 8), $name);
        $this->assertNotSame($name, $vecstore->build_collection_name_for_test(str_repeat('a', 79) . 'b'));
    }

    /**
     * A vector store instance without collection prefix cannot store vectors.
     */
    public function test_missing_collection_prefix_is_rejected(): void {
        $this->resetAfterTest();

        $vecstore = $this->create_vecstore('');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('exception_vecstorenocollection', 'local_ai_manager',
            get_string('pluginname', 'aivecstore_qdrant')));
        $vecstore->insert_embeddings('model-a', [$this->create_vector(1, [0.1, 0.2])]);
    }
}
