<?php

namespace Doctrine\DBAL\Tests\Functional\Schema;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Events;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Schema\View;
use Doctrine\DBAL\Tests\FunctionalTestCase;
use Doctrine\DBAL\Types\ArrayType;
use Doctrine\DBAL\Types\BinaryType;
use Doctrine\DBAL\Types\BlobType;
use Doctrine\DBAL\Types\DateIntervalType;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\DecimalType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\ObjectType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\Type;

use function array_filter;
use function array_keys;
use function array_map;
use function array_search;
use function array_values;
use function count;
use function current;
use function get_class;
use function sprintf;
use function strcasecmp;
use function strlen;
use function strtolower;
use function substr;

abstract class SchemaManagerFunctionalTestCase extends FunctionalTestCase
{
    /** @var AbstractSchemaManager */
    protected $schemaManager;

    abstract protected function supportsPlatform(AbstractPlatform $platform): bool;

    protected function setUp(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if (! $this->supportsPlatform($platform)) {
            self::markTestSkipped(sprintf('Skipping since connected to %s', get_class($platform)));
        }

        $this->schemaManager = $this->connection->getSchemaManager();
    }

    protected function tearDown(): void
    {
        if ($this->schemaManager->tablesExist(['json_array_test'])) {
            $this->schemaManager->dropTable('json_array_test');
        }

        $this->schemaManager->tryMethod('dropTable', 'testschema.my_table_in_namespace');

        //TODO: SchemaDiff does not drop removed namespaces?
        try {
            //sql server versions below 2016 do not support 'IF EXISTS' so we have to catch the exception here
            $this->connection->executeStatement('DROP SCHEMA testschema');
        } catch (Exception $e) {
        }

        $this->markConnectionNotReusable();
    }

    public function testDropAndCreateSequence(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if (! $platform->supportsSequences()) {
            self::markTestSkipped(
                sprintf('The "%s" platform does not support sequences.', $platform->getName())
            );
        }

        $name = 'dropcreate_sequences_test_seq';

        $this->schemaManager->dropAndCreateSequence(new Sequence($name, 20, 10));

        self::assertTrue($this->hasElementWithName($this->schemaManager->listSequences(), $name));
    }

    /**
     * @param AbstractAsset[] $items
     */
    private function hasElementWithName(array $items, string $name): bool
    {
        $filteredList = array_filter(
            $items,
            static function (AbstractAsset $item) use ($name): bool {
                return $item->getShortestName($item->getNamespaceName()) === $name;
            }
        );

        return count($filteredList) === 1;
    }

    public function testListSequences(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if (! $platform->supportsSequences()) {
            self::markTestSkipped(
                sprintf('The "%s" platform does not support sequences.', $platform->getName())
            );
        }

        $this->schemaManager->createSequence(
            new Sequence('list_sequences_test_seq', 20, 10)
        );

        $sequences = $this->schemaManager->listSequences();

        foreach ($sequences as $sequence) {
            if (strtolower($sequence->getName()) === 'list_sequences_test_seq') {
                self::assertSame(20, $sequence->getAllocationSize());
                self::assertSame(10, $sequence->getInitialValue());

                return;
            }
        }

        self::fail('Sequence was not found.');
    }

    public function testListDatabases(): void
    {
        if (! $this->schemaManager->getDatabasePlatform()->supportsCreateDropDatabase()) {
            self::markTestSkipped('Cannot drop Database client side with this Driver.');
        }

        $this->schemaManager->dropAndCreateDatabase('test_create_database');
        $databases = $this->schemaManager->listDatabases();

        $databases = array_map('strtolower', $databases);

        self::assertContains('test_create_database', $databases);
    }

    /**
     * @dataProvider listSchemaNamesMethodProvider
     */
    public function testListSchemaNames(callable $method): void
    {
        if (! $this->schemaManager->getDatabasePlatform()->supportsSchemas()) {
            self::markTestSkipped('Platform does not support schemas.');
        }

        $this->schemaManager->tryMethod('dropSchema', 'test_create_schema');

        $this->connection->executeStatement(
            $this->schemaManager->getDatabasePlatform()->getCreateSchemaSQL('test_create_schema')
        );

        self::assertContains('test_create_schema', $method($this->schemaManager));
    }

    /**
     * @return iterable<list<mixed>>
     */
    public static function listSchemaNamesMethodProvider(): iterable
    {
        yield [
            static function (AbstractSchemaManager $schemaManager): array {
                return $schemaManager->listNamespaceNames();
            },
        ];

        yield [
            static function (AbstractSchemaManager $schemaManager): array {
                return $schemaManager->listSchemaNames();
            },
        ];
    }

    public function testListTables(): void
    {
        $this->createTestTable('list_tables_test');
        $tables = $this->schemaManager->listTables();

        $foundTable = false;
        foreach ($tables as $table) {
            self::assertInstanceOf(Table::class, $table);
            if (strtolower($table->getName()) !== 'list_tables_test') {
                continue;
            }

            $foundTable = true;

            self::assertTrue($table->hasColumn('id'));
            self::assertTrue($table->hasColumn('test'));
            self::assertTrue($table->hasColumn('foreign_key_test'));
        }

        self::assertTrue($foundTable, "The 'list_tables_test' table has to be found.");
    }

    public function createListTableColumns(): Table
    {
        $table = new Table('list_table_columns');
        $table->addColumn('id', 'integer', ['notnull' => true]);
        $table->addColumn('test', 'string', ['length' => 255, 'notnull' => false, 'default' => 'expected default']);
        $table->addColumn('foo', 'text', ['notnull' => true]);
        $table->addColumn('bar', 'decimal', ['precision' => 10, 'scale' => 4, 'notnull' => false]);
        $table->addColumn('baz1', 'datetime');
        $table->addColumn('baz2', 'time');
        $table->addColumn('baz3', 'date');
        $table->setPrimaryKey(['id']);

        return $table;
    }

    public function testListTableColumns(): void
    {
        $table = $this->createListTableColumns();

        $this->schemaManager->dropAndCreateTable($table);

        $columns     = $this->schemaManager->listTableColumns('list_table_columns');
        $columnsKeys = array_keys($columns);

        self::assertArrayHasKey('id', $columns);
        self::assertEquals(0, array_search('id', $columnsKeys, true));
        self::assertEquals('id', strtolower($columns['id']->getName()));
        self::assertInstanceOf(IntegerType::class, $columns['id']->getType());
        self::assertEquals(false, $columns['id']->getUnsigned());
        self::assertEquals(true, $columns['id']->getNotnull());
        self::assertEquals(null, $columns['id']->getDefault());
        self::assertIsArray($columns['id']->getPlatformOptions());

        self::assertArrayHasKey('test', $columns);
        self::assertEquals(1, array_search('test', $columnsKeys, true));
        self::assertEquals('test', strtolower($columns['test']->getname()));
        self::assertInstanceOf(StringType::class, $columns['test']->gettype());
        self::assertEquals(255, $columns['test']->getlength());
        self::assertEquals(false, $columns['test']->getfixed());
        self::assertEquals(false, $columns['test']->getnotnull());
        self::assertEquals('expected default', $columns['test']->getdefault());
        self::assertIsArray($columns['test']->getPlatformOptions());

        self::assertEquals('foo', strtolower($columns['foo']->getname()));
        self::assertEquals(2, array_search('foo', $columnsKeys, true));
        self::assertInstanceOf(TextType::class, $columns['foo']->gettype());
        self::assertEquals(false, $columns['foo']->getunsigned());
        self::assertEquals(false, $columns['foo']->getfixed());
        self::assertEquals(true, $columns['foo']->getnotnull());
        self::assertEquals(null, $columns['foo']->getdefault());
        self::assertIsArray($columns['foo']->getPlatformOptions());

        self::assertEquals('bar', strtolower($columns['bar']->getname()));
        self::assertEquals(3, array_search('bar', $columnsKeys, true));
        self::assertInstanceOf(DecimalType::class, $columns['bar']->gettype());
        self::assertEquals(null, $columns['bar']->getlength());
        self::assertEquals(10, $columns['bar']->getprecision());
        self::assertEquals(4, $columns['bar']->getscale());
        self::assertEquals(false, $columns['bar']->getunsigned());
        self::assertEquals(false, $columns['bar']->getfixed());
        self::assertEquals(false, $columns['bar']->getnotnull());
        self::assertEquals(null, $columns['bar']->getdefault());
        self::assertIsArray($columns['bar']->getPlatformOptions());

        self::assertEquals('baz1', strtolower($columns['baz1']->getname()));
        self::assertEquals(4, array_search('baz1', $columnsKeys, true));
        self::assertInstanceOf(DateTimeType::class, $columns['baz1']->gettype());
        self::assertEquals(true, $columns['baz1']->getnotnull());
        self::assertEquals(null, $columns['baz1']->getdefault());
        self::assertIsArray($columns['baz1']->getPlatformOptions());

        self::assertEquals('baz2', strtolower($columns['baz2']->getname()));
        self::assertEquals(5, array_search('baz2', $columnsKeys, true));
        self::assertContains($columns['baz2']->gettype()->getName(), ['time', 'date', 'datetime']);
        self::assertEquals(true, $columns['baz2']->getnotnull());
        self::assertEquals(null, $columns['baz2']->getdefault());
        self::assertIsArray($columns['baz2']->getPlatformOptions());

        self::assertEquals('baz3', strtolower($columns['baz3']->getname()));
        self::assertEquals(6, array_search('baz3', $columnsKeys, true));
        self::assertContains($columns['baz3']->gettype()->getName(), ['time', 'date', 'datetime']);
        self::assertEquals(true, $columns['baz3']->getnotnull());
        self::assertEquals(null, $columns['baz3']->getdefault());
        self::assertIsArray($columns['baz3']->getPlatformOptions());
    }

    public function testListTableColumnsWithFixedStringColumn(): void
    {
        $tableName = 'test_list_table_fixed_string';

        $table = new Table($tableName);
        $table->addColumn('column_char', 'string', ['fixed' => true, 'length' => 2]);

        $this->schemaManager->createTable($table);

        $columns = $this->schemaManager->listTableColumns($tableName);

        self::assertArrayHasKey('column_char', $columns);
        self::assertInstanceOf(StringType::class, $columns['column_char']->getType());
        self::assertTrue($columns['column_char']->getFixed());
        self::assertSame(2, $columns['column_char']->getLength());
    }

    public function testListTableColumnsDispatchEvent(): void
    {
        $table = $this->createListTableColumns();

        $this->schemaManager->dropAndCreateTable($table);

        $listenerMock = $this->createMock(ListTableColumnsDispatchEventListener::class);
        $listenerMock
            ->expects(self::exactly(7))
            ->method('onSchemaColumnDefinition');

        $eventManager = new EventManager();
        $eventManager->addEventListener([Events::onSchemaColumnDefinition], $listenerMock);

        $this->schemaManager->getDatabasePlatform()->setEventManager($eventManager);

        $this->schemaManager->listTableColumns('list_table_columns');
    }

    public function testListTableIndexesDispatchEvent(): void
    {
        $table = $this->getTestTable('list_table_indexes_test');
        $table->addUniqueIndex(['test'], 'test_index_name');
        $table->addIndex(['id', 'test'], 'test_composite_idx');

        $this->schemaManager->dropAndCreateTable($table);

        $listenerMock = $this->createMock(ListTableIndexesDispatchEventListener::class);
        $listenerMock
            ->expects(self::exactly(3))
            ->method('onSchemaIndexDefinition');

        $eventManager = new EventManager();
        $eventManager->addEventListener([Events::onSchemaIndexDefinition], $listenerMock);

        $this->schemaManager->getDatabasePlatform()->setEventManager($eventManager);

        $this->schemaManager->listTableIndexes('list_table_indexes_test');
    }

    public function testDiffListTableColumns(): void
    {
        if ($this->schemaManager->getDatabasePlatform()->getName() === 'oracle') {
            self::markTestSkipped(
                'Does not work with Oracle, since it cannot detect DateTime, Date and Time differenecs (at the moment).'
            );
        }

        $offlineTable = $this->createListTableColumns();
        $this->schemaManager->dropAndCreateTable($offlineTable);
        $onlineTable = $this->schemaManager->listTableDetails('list_table_columns');

        $comparator = new Comparator();
        $diff       = $comparator->diffTable($offlineTable, $onlineTable);

        self::assertFalse($diff, 'No differences should be detected with the offline vs online schema.');
    }

    public function testListTableIndexes(): void
    {
        $table = $this->getTestCompositeTable('list_table_indexes_test');
        $table->addUniqueIndex(['test'], 'test_index_name');
        $table->addIndex(['id', 'test'], 'test_composite_idx');

        $this->schemaManager->dropAndCreateTable($table);

        $tableIndexes = $this->schemaManager->listTableIndexes('list_table_indexes_test');

        self::assertEquals(3, count($tableIndexes));

        self::assertArrayHasKey('primary', $tableIndexes, 'listTableIndexes() has to return a "primary" array key.');
        self::assertEquals(['id', 'other_id'], array_map('strtolower', $tableIndexes['primary']->getColumns()));
        self::assertTrue($tableIndexes['primary']->isUnique());
        self::assertTrue($tableIndexes['primary']->isPrimary());

        self::assertEquals('test_index_name', strtolower($tableIndexes['test_index_name']->getName()));
        self::assertEquals(['test'], array_map('strtolower', $tableIndexes['test_index_name']->getColumns()));
        self::assertTrue($tableIndexes['test_index_name']->isUnique());
        self::assertFalse($tableIndexes['test_index_name']->isPrimary());

        self::assertEquals('test_composite_idx', strtolower($tableIndexes['test_composite_idx']->getName()));
        self::assertEquals(['id', 'test'], array_map('strtolower', $tableIndexes['test_composite_idx']->getColumns()));
        self::assertFalse($tableIndexes['test_composite_idx']->isUnique());
        self::assertFalse($tableIndexes['test_composite_idx']->isPrimary());
    }

    public function testDropAndCreateIndex(): void
    {
        $table = $this->getTestTable('test_create_index');
        $table->addUniqueIndex(['test'], 'test');
        $this->schemaManager->dropAndCreateTable($table);

        $this->schemaManager->dropAndCreateIndex($table->getIndex('test'), $table);
        $tableIndexes = $this->schemaManager->listTableIndexes('test_create_index');

        self::assertEquals('test', strtolower($tableIndexes['test']->getName()));
        self::assertEquals(['test'], array_map('strtolower', $tableIndexes['test']->getColumns()));
        self::assertTrue($tableIndexes['test']->isUnique());
        self::assertFalse($tableIndexes['test']->isPrimary());
    }

    public function testCreateTableWithForeignKeys(): void
    {
        if (! $this->schemaManager->getDatabasePlatform()->supportsForeignKeyConstraints()) {
            self::markTestSkipped('Platform does not support foreign keys.');
        }

        $tableB = $this->getTestTable('test_foreign');

        $this->schemaManager->dropAndCreateTable($tableB);

        $tableA = $this->getTestTable('test_create_fk');
        $tableA->addForeignKeyConstraint('test_foreign', ['foreign_key_test'], ['id']);

        $this->schemaManager->dropAndCreateTable($tableA);

        $fkTable       = $this->schemaManager->listTableDetails('test_create_fk');
        $fkConstraints = $fkTable->getForeignKeys();
        self::assertEquals(1, count($fkConstraints), "Table 'test_create_fk1' has to have one foreign key.");

        $fkConstraint = current($fkConstraints);
        self::assertInstanceOf(ForeignKeyConstraint::class, $fkConstraint);
        self::assertEquals('test_foreign', strtolower($fkConstraint->getForeignTableName()));
        self::assertEquals(['foreign_key_test'], array_map('strtolower', $fkConstraint->getColumns()));
        self::assertEquals(['id'], array_map('strtolower', $fkConstraint->getForeignColumns()));

        self::assertTrue($fkTable->columnsAreIndexed($fkConstraint->getColumns()));
    }

    public function testListForeignKeys(): void
    {
        if (! $this->connection->getDatabasePlatform()->supportsForeignKeyConstraints()) {
            self::markTestSkipped('Does not support foreign key constraints.');
        }

        $this->createTestTable('test_create_fk1');
        $this->createTestTable('test_create_fk2');

        $foreignKey = new ForeignKeyConstraint(
            ['foreign_key_test'],
            'test_create_fk2',
            ['id'],
            'foreign_key_test_fk',
            ['onDelete' => 'CASCADE']
        );

        $this->schemaManager->createForeignKey($foreignKey, 'test_create_fk1');

        $fkeys = $this->schemaManager->listTableForeignKeys('test_create_fk1');

        self::assertEquals(1, count($fkeys), "Table 'test_create_fk1' has to have one foreign key.");

        self::assertInstanceOf(ForeignKeyConstraint::class, $fkeys[0]);
        self::assertEquals(['foreign_key_test'], array_map('strtolower', $fkeys[0]->getLocalColumns()));
        self::assertEquals(['id'], array_map('strtolower', $fkeys[0]->getForeignColumns()));
        self::assertEquals('test_create_fk2', strtolower($fkeys[0]->getForeignTableName()));

        if (! $fkeys[0]->hasOption('onDelete')) {
            return;
        }

        self::assertEquals('CASCADE', $fkeys[0]->getOption('onDelete'));
    }

    protected function getCreateExampleViewSql(): void
    {
        self::markTestSkipped('No Create Example View SQL was defined for this SchemaManager');
    }

    public function testCreateSchema(): void
    {
        $this->createTestTable('test_table');

        $schema = $this->schemaManager->createSchema();
        self::assertTrue($schema->hasTable('test_table'));
    }

    public function testAlterTableScenario(): void
    {
        if (! $this->schemaManager->getDatabasePlatform()->supportsAlterTable()) {
            self::markTestSkipped('Alter Table is not supported by this platform.');
        }

        $alterTable = $this->createTestTable('alter_table');
        $this->createTestTable('alter_table_foreign');

        $table = $this->schemaManager->listTableDetails('alter_table');
        self::assertTrue($table->hasColumn('id'));
        self::assertTrue($table->hasColumn('test'));
        self::assertTrue($table->hasColumn('foreign_key_test'));
        self::assertEquals(0, count($table->getForeignKeys()));
        self::assertEquals(1, count($table->getIndexes()));

        $tableDiff                         = new TableDiff('alter_table');
        $tableDiff->fromTable              = $alterTable;
        $tableDiff->addedColumns['foo']    = new Column('foo', Type::getType('integer'));
        $tableDiff->removedColumns['test'] = $table->getColumn('test');

        $this->schemaManager->alterTable($tableDiff);

        $table = $this->schemaManager->listTableDetails('alter_table');
        self::assertFalse($table->hasColumn('test'));
        self::assertTrue($table->hasColumn('foo'));

        $tableDiff                 = new TableDiff('alter_table');
        $tableDiff->fromTable      = $table;
        $tableDiff->addedIndexes[] = new Index('foo_idx', ['foo']);

        $this->schemaManager->alterTable($tableDiff);

        $table = $this->schemaManager->listTableDetails('alter_table');
        self::assertEquals(2, count($table->getIndexes()));
        self::assertTrue($table->hasIndex('foo_idx'));
        self::assertEquals(['foo'], array_map('strtolower', $table->getIndex('foo_idx')->getColumns()));
        self::assertFalse($table->getIndex('foo_idx')->isPrimary());
        self::assertFalse($table->getIndex('foo_idx')->isUnique());

        $tableDiff                   = new TableDiff('alter_table');
        $tableDiff->fromTable        = $table;
        $tableDiff->changedIndexes[] = new Index('foo_idx', ['foo', 'foreign_key_test']);

        $this->schemaManager->alterTable($tableDiff);

        $table = $this->schemaManager->listTableDetails('alter_table');
        self::assertEquals(2, count($table->getIndexes()));
        self::assertTrue($table->hasIndex('foo_idx'));
        self::assertEquals(
            ['foo', 'foreign_key_test'],
            array_map('strtolower', $table->getIndex('foo_idx')->getColumns())
        );

        $tableDiff                            = new TableDiff('alter_table');
        $tableDiff->fromTable                 = $table;
        $tableDiff->renamedIndexes['foo_idx'] = new Index('bar_idx', ['foo', 'foreign_key_test']);

        $this->schemaManager->alterTable($tableDiff);

        $table = $this->schemaManager->listTableDetails('alter_table');
        self::assertEquals(2, count($table->getIndexes()));
        self::assertTrue($table->hasIndex('bar_idx'));
        self::assertFalse($table->hasIndex('foo_idx'));
        self::assertEquals(
            ['foo', 'foreign_key_test'],
            array_map('strtolower', $table->getIndex('bar_idx')->getColumns())
        );
        self::assertFalse($table->getIndex('bar_idx')->isPrimary());
        self::assertFalse($table->getIndex('bar_idx')->isUnique());

        $tableDiff                     = new TableDiff('alter_table');
        $tableDiff->fromTable          = $table;
        $tableDiff->removedIndexes[]   = new Index('bar_idx', ['foo', 'foreign_key_test']);
        $fk                            = new ForeignKeyConstraint(['foreign_key_test'], 'alter_table_foreign', ['id']);
        $tableDiff->addedForeignKeys[] = $fk;

        $this->schemaManager->alterTable($tableDiff);
        $table = $this->schemaManager->listTableDetails('alter_table');

        // dont check for index size here, some platforms automatically add indexes for foreign keys.
        self::assertFalse($table->hasIndex('bar_idx'));

        if (! $this->schemaManager->getDatabasePlatform()->supportsForeignKeyConstraints()) {
            return;
        }

        $fks = $table->getForeignKeys();
        self::assertCount(1, $fks);
        $foreignKey = current($fks);
        self::assertEquals('alter_table_foreign', strtolower($foreignKey->getForeignTableName()));
        self::assertEquals(['foreign_key_test'], array_map('strtolower', $foreignKey->getColumns()));
        self::assertEquals(['id'], array_map('strtolower', $foreignKey->getForeignColumns()));
    }

    public function testTableInNamespace(): void
    {
        if (! $this->schemaManager->getDatabasePlatform()->supportsSchemas()) {
            self::markTestSkipped('Schema definition is not supported by this platform.');
        }

        //create schema
        $diff                  = new SchemaDiff();
        $diff->newNamespaces[] = 'testschema';

        foreach ($diff->toSql($this->schemaManager->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }

        //test if table is create in namespace
        $this->createTestTable('testschema.my_table_in_namespace');
        self::assertContains('testschema.my_table_in_namespace', $this->schemaManager->listTableNames());

        //tables without namespace should be created in default namespace
        //default namespaces are ignored in table listings
        $this->createTestTable('my_table_not_in_namespace');
        self::assertContains('my_table_not_in_namespace', $this->schemaManager->listTableNames());
    }

    public function testCreateAndListViews(): void
    {
        if (! $this->schemaManager->getDatabasePlatform()->supportsViews()) {
            self::markTestSkipped('Views is not supported by this platform.');
        }

        $this->createTestTable('view_test_table');

        $name = 'doctrine_test_view';
        $sql  = 'SELECT * FROM view_test_table';

        $view = new View($name, $sql);

        $this->schemaManager->dropAndCreateView($view);

        self::assertTrue($this->hasElementWithName($this->schemaManager->listViews(), $name));
    }

    public function testAutoincrementDetection(): void
    {
        if (! $this->schemaManager->getDatabasePlatform()->supportsIdentityColumns()) {
            self::markTestSkipped('This test is only supported on platforms that have autoincrement');
        }

        $table = new Table('test_autoincrement');
        $table->setSchemaConfig($this->schemaManager->createSchemaConfig());
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);

        $this->schemaManager->createTable($table);

        $inferredTable = $this->schemaManager->listTableDetails('test_autoincrement');
        self::assertTrue($inferredTable->hasColumn('id'));
        self::assertTrue($inferredTable->getColumn('id')->getAutoincrement());
    }

    public function testAutoincrementDetectionMulticolumns(): void
    {
        if (! $this->schemaManager->getDatabasePlatform()->supportsIdentityColumns()) {
            self::markTestSkipped('This test is only supported on platforms that have autoincrement');
        }

        $table = new Table('test_not_autoincrement');
        $table->setSchemaConfig($this->schemaManager->createSchemaConfig());
        $table->addColumn('id', 'integer');
        $table->addColumn('other_id', 'integer');
        $table->setPrimaryKey(['id', 'other_id']);

        $this->schemaManager->createTable($table);

        $inferredTable = $this->schemaManager->listTableDetails('test_not_autoincrement');
        self::assertTrue($inferredTable->hasColumn('id'));
        self::assertFalse($inferredTable->getColumn('id')->getAutoincrement());
    }

    public function testUpdateSchemaWithForeignKeyRenaming(): void
    {
        if (! $this->schemaManager->getDatabasePlatform()->supportsForeignKeyConstraints()) {
            self::markTestSkipped('This test is only supported on platforms that have foreign keys.');
        }

        $table = new Table('test_fk_base');
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(['id']);

        $tableFK = new Table('test_fk_rename');
        $tableFK->setSchemaConfig($this->schemaManager->createSchemaConfig());
        $tableFK->addColumn('id', 'integer');
        $tableFK->addColumn('fk_id', 'integer');
        $tableFK->setPrimaryKey(['id']);
        $tableFK->addIndex(['fk_id'], 'fk_idx');
        $tableFK->addForeignKeyConstraint('test_fk_base', ['fk_id'], ['id']);

        $this->schemaManager->createTable($table);
        $this->schemaManager->createTable($tableFK);

        $tableFKNew = new Table('test_fk_rename');
        $tableFKNew->setSchemaConfig($this->schemaManager->createSchemaConfig());
        $tableFKNew->addColumn('id', 'integer');
        $tableFKNew->addColumn('rename_fk_id', 'integer');
        $tableFKNew->setPrimaryKey(['id']);
        $tableFKNew->addIndex(['rename_fk_id'], 'fk_idx');
        $tableFKNew->addForeignKeyConstraint('test_fk_base', ['rename_fk_id'], ['id']);

        $diff = (new Comparator())->diffTable($tableFK, $tableFKNew);
        self::assertNotFalse($diff);

        $this->schemaManager->alterTable($diff);

        $table       = $this->schemaManager->listTableDetails('test_fk_rename');
        $foreignKeys = $table->getForeignKeys();

        self::assertTrue($table->hasColumn('rename_fk_id'));
        self::assertCount(1, $foreignKeys);
        self::assertSame(['rename_fk_id'], array_map('strtolower', current($foreignKeys)->getColumns()));
    }

    public function testRenameIndexUsedInForeignKeyConstraint(): void
    {
        if (! $this->schemaManager->getDatabasePlatform()->supportsForeignKeyConstraints()) {
            self::markTestSkipped('This test is only supported on platforms that have foreign keys.');
        }

        $primaryTable = new Table('test_rename_index_primary');
        $primaryTable->addColumn('id', 'integer');
        $primaryTable->setPrimaryKey(['id']);

        $foreignTable = new Table('test_rename_index_foreign');
        $foreignTable->addColumn('fk', 'integer');
        $foreignTable->addIndex(['fk'], 'rename_index_fk_idx');
        $foreignTable->addForeignKeyConstraint(
            'test_rename_index_primary',
            ['fk'],
            ['id'],
            [],
            'fk_constraint'
        );

        $this->schemaManager->dropAndCreateTable($primaryTable);
        $this->schemaManager->dropAndCreateTable($foreignTable);

        $foreignTable2 = clone $foreignTable;
        $foreignTable2->renameIndex('rename_index_fk_idx', 'renamed_index_fk_idx');

        $diff = (new Comparator())->diffTable($foreignTable, $foreignTable2);
        self::assertNotFalse($diff);

        $this->schemaManager->alterTable($diff);

        $foreignTable = $this->schemaManager->listTableDetails('test_rename_index_foreign');

        self::assertFalse($foreignTable->hasIndex('rename_index_fk_idx'));
        self::assertTrue($foreignTable->hasIndex('renamed_index_fk_idx'));
        self::assertTrue($foreignTable->hasForeignKey('fk_constraint'));
    }

    public function testGetColumnComment(): void
    {
        if (
            ! $this->connection->getDatabasePlatform()->supportsInlineColumnComments() &&
             ! $this->connection->getDatabasePlatform()->supportsCommentOnStatement() &&
            $this->connection->getDatabasePlatform()->getName() !== 'mssql'
        ) {
            self::markTestSkipped('Database does not support column comments.');
        }

        $table = new Table('column_comment_test');
        $table->addColumn('id', 'integer', ['comment' => 'This is a comment']);
        $table->setPrimaryKey(['id']);

        $this->schemaManager->createTable($table);

        $columns = $this->schemaManager->listTableColumns('column_comment_test');
        self::assertEquals(1, count($columns));
        self::assertEquals('This is a comment', $columns['id']->getComment());

        $tableDiff                       = new TableDiff('column_comment_test');
        $tableDiff->fromTable            = $table;
        $tableDiff->changedColumns['id'] = new ColumnDiff(
            'id',
            new Column(
                'id',
                Type::getType('integer')
            ),
            ['comment'],
            new Column(
                'id',
                Type::getType('integer'),
                ['comment' => 'This is a comment']
            )
        );

        $this->schemaManager->alterTable($tableDiff);

        $columns = $this->schemaManager->listTableColumns('column_comment_test');
        self::assertEquals(1, count($columns));
        self::assertEmpty($columns['id']->getComment());
    }

    public function testAutomaticallyAppendCommentOnMarkedColumns(): void
    {
        if (
            ! $this->connection->getDatabasePlatform()->supportsInlineColumnComments() &&
             ! $this->connection->getDatabasePlatform()->supportsCommentOnStatement() &&
            $this->connection->getDatabasePlatform()->getName() !== 'mssql'
        ) {
            self::markTestSkipped('Database does not support column comments.');
        }

        $table = new Table('column_comment_test2');
        $table->addColumn('id', 'integer', ['comment' => 'This is a comment']);
        $table->addColumn('obj', 'object', ['comment' => 'This is a comment']);
        $table->addColumn('arr', 'array', ['comment' => 'This is a comment']);
        $table->setPrimaryKey(['id']);

        $this->schemaManager->createTable($table);

        $columns = $this->schemaManager->listTableColumns('column_comment_test2');
        self::assertEquals(3, count($columns));
        self::assertEquals('This is a comment', $columns['id']->getComment());
        self::assertEquals('This is a comment', $columns['obj']->getComment());
        self::assertInstanceOf(ObjectType::class, $columns['obj']->getType());
        self::assertEquals('This is a comment', $columns['arr']->getComment());
        self::assertInstanceOf(ArrayType::class, $columns['arr']->getType());
    }

    public function testCommentHintOnDateIntervalTypeColumn(): void
    {
        if (
            ! $this->connection->getDatabasePlatform()->supportsInlineColumnComments() &&
            ! $this->connection->getDatabasePlatform()->supportsCommentOnStatement() &&
            $this->connection->getDatabasePlatform()->getName() !== 'mssql'
        ) {
            self::markTestSkipped('Database does not support column comments.');
        }

        $table = new Table('column_dateinterval_comment');
        $table->addColumn('id', 'integer', ['comment' => 'This is a comment']);
        $table->addColumn('date_interval', 'dateinterval', ['comment' => 'This is a comment']);
        $table->setPrimaryKey(['id']);

        $this->schemaManager->createTable($table);

        $columns = $this->schemaManager->listTableColumns('column_dateinterval_comment');
        self::assertEquals(2, count($columns));
        self::assertEquals('This is a comment', $columns['id']->getComment());
        self::assertEquals('This is a comment', $columns['date_interval']->getComment());
        self::assertInstanceOf(DateIntervalType::class, $columns['date_interval']->getType());
    }

    public function testChangeColumnsTypeWithDefaultValue(): void
    {
        $tableName = 'column_def_change_type';
        $table     = new Table($tableName);

        $table->addColumn('col_int', 'smallint', ['default' => 666]);
        $table->addColumn('col_string', 'string', ['default' => 'foo']);

        $this->schemaManager->dropAndCreateTable($table);

        $tableDiff                            = new TableDiff($tableName);
        $tableDiff->fromTable                 = $table;
        $tableDiff->changedColumns['col_int'] = new ColumnDiff(
            'col_int',
            new Column('col_int', Type::getType('integer'), ['default' => 666]),
            ['type'],
            new Column('col_int', Type::getType('smallint'), ['default' => 666])
        );

        $tableDiff->changedColumns['col_string'] = new ColumnDiff(
            'col_string',
            new Column('col_string', Type::getType('string'), ['default' => 'foo', 'fixed' => true]),
            ['fixed'],
            new Column('col_string', Type::getType('string'), ['default' => 'foo'])
        );

        $this->schemaManager->alterTable($tableDiff);

        $columns = $this->schemaManager->listTableColumns($tableName);

        self::assertInstanceOf(IntegerType::class, $columns['col_int']->getType());
        self::assertEquals(666, $columns['col_int']->getDefault());

        self::assertInstanceOf(StringType::class, $columns['col_string']->getType());
        self::assertEquals('foo', $columns['col_string']->getDefault());
    }

    public function testListTableWithBlob(): void
    {
        $table = new Table('test_blob_table');
        $table->addColumn('binarydata', 'blob', []);

        $this->schemaManager->createTable($table);

        $created = $this->schemaManager->listTableDetails('test_blob_table');

        self::assertTrue($created->hasColumn('binarydata'));
        self::assertInstanceOf(BlobType::class, $created->getColumn('binarydata')->getType());
    }

    /**
     * @param mixed[] $data
     */
    protected function createTestTable(string $name = 'test_table', array $data = []): Table
    {
        $options = $data['options'] ?? [];

        $table = $this->getTestTable($name, $options);

        $this->schemaManager->dropAndCreateTable($table);

        return $table;
    }

    /**
     * @param mixed[] $options
     */
    protected function getTestTable(string $name, array $options = []): Table
    {
        $table = new Table($name, [], [], [], [], $options);
        $table->setSchemaConfig($this->schemaManager->createSchemaConfig());
        $table->addColumn('id', 'integer', ['notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addColumn('test', 'string', ['length' => 255]);
        $table->addColumn('foreign_key_test', 'integer');

        return $table;
    }

    protected function getTestCompositeTable(string $name): Table
    {
        $table = new Table($name, [], [], [], [], []);
        $table->setSchemaConfig($this->schemaManager->createSchemaConfig());
        $table->addColumn('id', 'integer', ['notnull' => true]);
        $table->addColumn('other_id', 'integer', ['notnull' => true]);
        $table->setPrimaryKey(['id', 'other_id']);
        $table->addColumn('test', 'string', ['length' => 255]);

        return $table;
    }

    /**
     * @param Table[] $tables
     */
    protected function assertHasTable(array $tables): void
    {
        $foundTable = false;
        foreach ($tables as $table) {
            self::assertInstanceOf(Table::class, $table, 'No Table instance was found in tables array.');
            if (strtolower($table->getName()) !== 'list_tables_test_new_name') {
                continue;
            }

            $foundTable = true;
        }

        self::assertTrue($foundTable, 'Could not find new table');
    }

    public function testListForeignKeysComposite(): void
    {
        if (! $this->connection->getDatabasePlatform()->supportsForeignKeyConstraints()) {
            self::markTestSkipped('Does not support foreign key constraints.');
        }

        $this->schemaManager->createTable($this->getTestTable('test_create_fk3'));
        $this->schemaManager->createTable($this->getTestCompositeTable('test_create_fk4'));

        $foreignKey = new ForeignKeyConstraint(
            ['id', 'foreign_key_test'],
            'test_create_fk4',
            ['id', 'other_id'],
            'foreign_key_test_fk2'
        );

        $this->schemaManager->createForeignKey($foreignKey, 'test_create_fk3');

        $fkeys = $this->schemaManager->listTableForeignKeys('test_create_fk3');

        self::assertEquals(1, count($fkeys), "Table 'test_create_fk3' has to have one foreign key.");

        self::assertInstanceOf(ForeignKeyConstraint::class, $fkeys[0]);
        self::assertEquals(['id', 'foreign_key_test'], array_map('strtolower', $fkeys[0]->getLocalColumns()));
        self::assertEquals(['id', 'other_id'], array_map('strtolower', $fkeys[0]->getForeignColumns()));
    }

    public function testColumnDefaultLifecycle(): void
    {
        $table = new Table('col_def_lifecycle');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('column1', 'string', ['default' => null]);
        $table->addColumn('column2', 'string', ['default' => false]);
        $table->addColumn('column3', 'string', ['default' => true]);
        $table->addColumn('column4', 'string', ['default' => 0]);
        $table->addColumn('column5', 'string', ['default' => '']);
        $table->addColumn('column6', 'string', ['default' => 'def']);
        $table->addColumn('column7', 'integer', ['default' => 0]);
        $table->setPrimaryKey(['id']);

        $this->schemaManager->dropAndCreateTable($table);

        $columns = $this->schemaManager->listTableColumns('col_def_lifecycle');

        self::assertNull($columns['id']->getDefault());
        self::assertNull($columns['column1']->getDefault());
        self::assertSame('', $columns['column2']->getDefault());
        self::assertSame('1', $columns['column3']->getDefault());
        self::assertSame('0', $columns['column4']->getDefault());
        self::assertSame('', $columns['column5']->getDefault());
        self::assertSame('def', $columns['column6']->getDefault());
        self::assertSame('0', $columns['column7']->getDefault());

        $diffTable = clone $table;

        $diffTable->changeColumn('column1', ['default' => false]);
        $diffTable->changeColumn('column2', ['default' => null]);
        $diffTable->changeColumn('column3', ['default' => false]);
        $diffTable->changeColumn('column4', ['default' => null]);
        $diffTable->changeColumn('column5', ['default' => false]);
        $diffTable->changeColumn('column6', ['default' => 666]);
        $diffTable->changeColumn('column7', ['default' => null]);

        $diff = (new Comparator())->diffTable($table, $diffTable);
        self::assertNotFalse($diff);

        $this->schemaManager->alterTable($diff);

        $columns = $this->schemaManager->listTableColumns('col_def_lifecycle');

        self::assertSame('', $columns['column1']->getDefault());
        self::assertNull($columns['column2']->getDefault());
        self::assertSame('', $columns['column3']->getDefault());
        self::assertNull($columns['column4']->getDefault());
        self::assertSame('', $columns['column5']->getDefault());
        self::assertSame('666', $columns['column6']->getDefault());
        self::assertNull($columns['column7']->getDefault());
    }

    public function testListTableWithBinary(): void
    {
        $tableName = 'test_binary_table';

        $table = new Table($tableName);
        $table->addColumn('column_binary', 'binary', ['length' => 16, 'fixed' => true]);
        $table->addColumn('column_varbinary', 'binary', ['length' => 32]);

        $this->schemaManager->createTable($table);

        $table = $this->schemaManager->listTableDetails($tableName);
        $this->assertBinaryColumnIsValid($table, 'column_binary', 16);
        $this->assertVarBinaryColumnIsValid($table, 'column_varbinary', 32);
    }

    protected function assertBinaryColumnIsValid(Table $table, string $columnName, int $expectedLength): void
    {
        $column = $table->getColumn($columnName);
        self::assertInstanceOf(BinaryType::class, $column->getType());
        self::assertSame($expectedLength, $column->getLength());
        self::assertTrue($column->getFixed());
    }

    protected function assertVarBinaryColumnIsValid(Table $table, string $columnName, int $expectedLength): void
    {
        $column = $table->getColumn($columnName);
        self::assertInstanceOf(BinaryType::class, $column->getType());
        self::assertSame($expectedLength, $column->getLength());
        self::assertFalse($column->getFixed());
    }

    public function testListTableDetailsWithFullQualifiedTableName(): void
    {
        if (! $this->schemaManager->getDatabasePlatform()->supportsSchemas()) {
            self::markTestSkipped('Test only works on platforms that support schemas.');
        }

        $defaultSchemaName = $this->schemaManager->getDatabasePlatform()->getDefaultSchemaName();
        $primaryTableName  = 'primary_table';
        $foreignTableName  = 'foreign_table';

        $table = new Table($foreignTableName);
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);

        $this->schemaManager->dropAndCreateTable($table);

        $table = new Table($primaryTableName);
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('foo', 'integer');
        $table->addColumn('bar', 'string');
        $table->addForeignKeyConstraint($foreignTableName, ['foo'], ['id']);
        $table->addIndex(['bar']);
        $table->setPrimaryKey(['id']);

        $this->schemaManager->dropAndCreateTable($table);

        self::assertEquals(
            $this->schemaManager->listTableColumns($primaryTableName),
            $this->schemaManager->listTableColumns($defaultSchemaName . '.' . $primaryTableName)
        );
        self::assertEquals(
            $this->schemaManager->listTableIndexes($primaryTableName),
            $this->schemaManager->listTableIndexes($defaultSchemaName . '.' . $primaryTableName)
        );
        self::assertEquals(
            $this->schemaManager->listTableForeignKeys($primaryTableName),
            $this->schemaManager->listTableForeignKeys($defaultSchemaName . '.' . $primaryTableName)
        );
    }

    public function testCommentStringsAreQuoted(): void
    {
        if (
            ! $this->connection->getDatabasePlatform()->supportsInlineColumnComments() &&
            ! $this->connection->getDatabasePlatform()->supportsCommentOnStatement() &&
            $this->connection->getDatabasePlatform()->getName() !== 'mssql'
        ) {
            self::markTestSkipped('Database does not support column comments.');
        }

        $table = new Table('my_table');
        $table->addColumn('id', 'integer', ['comment' => "It's a comment with a quote"]);
        $table->setPrimaryKey(['id']);

        $this->schemaManager->createTable($table);

        $columns = $this->schemaManager->listTableColumns('my_table');
        self::assertEquals("It's a comment with a quote", $columns['id']->getComment());
    }

    public function testCommentNotDuplicated(): void
    {
        if (! $this->connection->getDatabasePlatform()->supportsInlineColumnComments()) {
            self::markTestSkipped('Database does not support column comments.');
        }

        $options = [
            'type' => Type::getType('integer'),
            'default' => 0,
            'notnull' => true,
            'comment' => 'expected+column+comment',
        ];

        $columnDefinition = substr(
            $this->connection->getDatabasePlatform()->getColumnDeclarationSQL('id', $options),
            strlen('id') + 1
        );

        $table = new Table('my_table');
        $table->addColumn('id', 'integer', [
            'columnDefinition' => $columnDefinition,
            'comment' => 'unexpected_column_comment',
        ]);

        $sql = $this->connection->getDatabasePlatform()->getCreateTableSQL($table);

        self::assertStringContainsString('expected+column+comment', $sql[0]);
        self::assertStringNotContainsString('unexpected_column_comment', $sql[0]);
    }

    /**
     * @dataProvider getAlterColumnComment
     */
    public function testAlterColumnComment(
        ?string $comment1,
        ?string $expectedComment1,
        ?string $comment2,
        ?string $expectedComment2
    ): void {
        if (
            ! $this->connection->getDatabasePlatform()->supportsInlineColumnComments() &&
            ! $this->connection->getDatabasePlatform()->supportsCommentOnStatement() &&
            $this->connection->getDatabasePlatform()->getName() !== 'mssql'
        ) {
            self::markTestSkipped('Database does not support column comments.');
        }

        $offlineTable = new Table('alter_column_comment_test');
        $offlineTable->addColumn('comment1', 'integer', ['comment' => $comment1]);
        $offlineTable->addColumn('comment2', 'integer', ['comment' => $comment2]);
        $offlineTable->addColumn('no_comment1', 'integer');
        $offlineTable->addColumn('no_comment2', 'integer');
        $this->schemaManager->dropAndCreateTable($offlineTable);

        $onlineTable = $this->schemaManager->listTableDetails('alter_column_comment_test');

        self::assertSame($expectedComment1, $onlineTable->getColumn('comment1')->getComment());
        self::assertSame($expectedComment2, $onlineTable->getColumn('comment2')->getComment());
        self::assertNull($onlineTable->getColumn('no_comment1')->getComment());
        self::assertNull($onlineTable->getColumn('no_comment2')->getComment());

        $onlineTable->changeColumn('comment1', ['comment' => $comment2]);
        $onlineTable->changeColumn('comment2', ['comment' => $comment1]);
        $onlineTable->changeColumn('no_comment1', ['comment' => $comment1]);
        $onlineTable->changeColumn('no_comment2', ['comment' => $comment2]);

        $comparator = new Comparator();

        $tableDiff = $comparator->diffTable($offlineTable, $onlineTable);

        self::assertInstanceOf(TableDiff::class, $tableDiff);

        $this->schemaManager->alterTable($tableDiff);

        $onlineTable = $this->schemaManager->listTableDetails('alter_column_comment_test');

        self::assertSame($expectedComment2, $onlineTable->getColumn('comment1')->getComment());
        self::assertSame($expectedComment1, $onlineTable->getColumn('comment2')->getComment());
        self::assertSame($expectedComment1, $onlineTable->getColumn('no_comment1')->getComment());
        self::assertSame($expectedComment2, $onlineTable->getColumn('no_comment2')->getComment());
    }

    /**
     * @return mixed[][]
     */
    public static function getAlterColumnComment(): iterable
    {
        return [
            [null, null, ' ', ' '],
            [null, null, '0', '0'],
            [null, null, 'foo', 'foo'],

            ['', null, ' ', ' '],
            ['', null, '0', '0'],
            ['', null, 'foo', 'foo'],

            [' ', ' ', '0', '0'],
            [' ', ' ', 'foo', 'foo'],

            ['0', '0', 'foo', 'foo'],
        ];
    }

    public function testDoesNotListIndexesImplicitlyCreatedByForeignKeys(): void
    {
        if (! $this->schemaManager->getDatabasePlatform()->supportsForeignKeyConstraints()) {
            self::markTestSkipped('This test is only supported on platforms that have foreign keys.');
        }

        $primaryTable = new Table('test_list_index_impl_primary');
        $primaryTable->addColumn('id', 'integer');
        $primaryTable->setPrimaryKey(['id']);

        $foreignTable = new Table('test_list_index_impl_foreign');
        $foreignTable->addColumn('fk1', 'integer');
        $foreignTable->addColumn('fk2', 'integer');
        $foreignTable->addIndex(['fk1'], 'explicit_fk1_idx');
        $foreignTable->addForeignKeyConstraint('test_list_index_impl_primary', ['fk1'], ['id']);
        $foreignTable->addForeignKeyConstraint('test_list_index_impl_primary', ['fk2'], ['id']);

        $this->schemaManager->dropAndCreateTable($primaryTable);
        $this->schemaManager->dropAndCreateTable($foreignTable);

        $indexes = $this->schemaManager->listTableIndexes('test_list_index_impl_foreign');

        self::assertCount(2, $indexes);
        self::assertArrayHasKey('explicit_fk1_idx', $indexes);
        self::assertArrayHasKey('idx_3d6c147fdc58d6c', $indexes);
    }

    public function testComparatorShouldNotAddCommentToJsonTypeSinceItIsTheDefaultNow(): void
    {
        if (! $this->schemaManager->getDatabasePlatform()->hasNativeJsonType()) {
            self::markTestSkipped('This test is only supported on platforms that have native JSON type.');
        }

        $this->connection->executeQuery('CREATE TABLE json_test (parameters JSON NOT NULL)');

        $table = new Table('json_test');
        $table->addColumn('parameters', 'json');

        $comparator = new Comparator();
        $tableDiff  = $comparator->diffTable($this->schemaManager->listTableDetails('json_test'), $table);

        self::assertFalse($tableDiff);
    }

    /**
     * @dataProvider commentsProvider
     */
    public function testExtractDoctrineTypeFromComment(string $comment, string $expected, string $currentType): void
    {
        $result = $this->schemaManager->extractDoctrineTypeFromComment($comment, $currentType);

        self::assertSame($expected, $result);
    }

    /**
     * @return string[][]
     */
    public function commentsProvider(): array
    {
        $currentType = 'current type';

        return [
            'invalid custom type comments'      => ['should.return.current.type', $currentType, $currentType],
            'valid doctrine type'               => ['(DC2Type:guid)', 'guid', $currentType],
            'valid with dots'                   => ['(DC2Type:type.should.return)', 'type.should.return', $currentType],
            'valid with namespace'              => ['(DC2Type:Namespace\Class)', 'Namespace\Class', $currentType],
            'valid with extra closing bracket'  => ['(DC2Type:should.stop)).before)', 'should.stop', $currentType],
            'valid with extra opening brackets' => ['(DC2Type:should((.stop)).before)', 'should((.stop', $currentType],
        ];
    }

    public function testCreateAndListSequences(): void
    {
        if (! $this->schemaManager->getDatabasePlatform()->supportsSequences()) {
            self::markTestSkipped('This test is only supported on platforms that support sequences.');
        }

        $sequence1Name           = 'sequence_1';
        $sequence1AllocationSize = 1;
        $sequence1InitialValue   = 2;
        $sequence2Name           = 'sequence_2';
        $sequence2AllocationSize = 3;
        $sequence2InitialValue   = 4;
        $sequence1               = new Sequence($sequence1Name, $sequence1AllocationSize, $sequence1InitialValue);
        $sequence2               = new Sequence($sequence2Name, $sequence2AllocationSize, $sequence2InitialValue);

        $this->schemaManager->createSequence($sequence1);
        $this->schemaManager->createSequence($sequence2);

        /** @var Sequence[] $actualSequences */
        $actualSequences = [];
        foreach ($this->schemaManager->listSequences() as $sequence) {
            $actualSequences[$sequence->getName()] = $sequence;
        }

        $actualSequence1 = $actualSequences[$sequence1Name];
        $actualSequence2 = $actualSequences[$sequence2Name];

        self::assertSame($sequence1Name, $actualSequence1->getName());
        self::assertEquals($sequence1AllocationSize, $actualSequence1->getAllocationSize());
        self::assertEquals($sequence1InitialValue, $actualSequence1->getInitialValue());

        self::assertSame($sequence2Name, $actualSequence2->getName());
        self::assertEquals($sequence2AllocationSize, $actualSequence2->getAllocationSize());
        self::assertEquals($sequence2InitialValue, $actualSequence2->getInitialValue());
    }

    public function testComparisonWithAutoDetectedSequenceDefinition(): void
    {
        if (! $this->schemaManager->getDatabasePlatform()->supportsSequences()) {
            self::markTestSkipped('This test is only supported on platforms that support sequences.');
        }

        $sequenceName           = 'sequence_auto_detect_test';
        $sequenceAllocationSize = 5;
        $sequenceInitialValue   = 10;
        $sequence               = new Sequence($sequenceName, $sequenceAllocationSize, $sequenceInitialValue);

        $this->schemaManager->dropAndCreateSequence($sequence);

        $createdSequence = array_values(
            array_filter(
                $this->schemaManager->listSequences(),
                static function (Sequence $sequence) use ($sequenceName): bool {
                    return strcasecmp($sequence->getName(), $sequenceName) === 0;
                }
            )
        )[0] ?? null;

        self::assertNotNull($createdSequence);

        $comparator = new Comparator();
        $tableDiff  = $comparator->diffSequence($createdSequence, $sequence);

        self::assertFalse($tableDiff);
    }

    public function testPrimaryKeyAutoIncrement(): void
    {
        $table = new Table('test_pk_auto_increment');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('text', 'string');
        $table->setPrimaryKey(['id']);
        $this->schemaManager->dropAndCreateTable($table);

        $this->connection->insert('test_pk_auto_increment', ['text' => '1']);

        $lastUsedIdBeforeDelete = (int) $this->connection->fetchOne(
            "SELECT id FROM test_pk_auto_increment WHERE text = '1'"
        );

        $this->connection->executeStatement('DELETE FROM test_pk_auto_increment');

        $this->connection->insert('test_pk_auto_increment', ['text' => '2']);

        $lastUsedIdAfterDelete = (int) $this->connection->fetchOne(
            "SELECT id FROM test_pk_auto_increment WHERE text = '2'"
        );

        self::assertGreaterThan($lastUsedIdBeforeDelete, $lastUsedIdAfterDelete);
    }

    public function testGenerateAnIndexWithPartialColumnLength(): void
    {
        if (! $this->schemaManager->getDatabasePlatform()->supportsColumnLengthIndexes()) {
            self::markTestSkipped(
                'This test is only supported on platforms that support indexes with column length definitions.'
            );
        }

        $table = new Table('test_partial_column_index');
        $table->addColumn('long_column', 'string', ['length' => 40]);
        $table->addColumn('standard_column', 'integer');
        $table->addIndex(['long_column'], 'partial_long_column_idx', [], ['lengths' => [4]]);
        $table->addIndex(['standard_column', 'long_column'], 'standard_and_partial_idx', [], ['lengths' => [null, 2]]);

        $expected = $table->getIndexes();

        $this->schemaManager->dropAndCreateTable($table);

        $onlineTable = $this->schemaManager->listTableDetails('test_partial_column_index');
        self::assertEquals($expected, $onlineTable->getIndexes());
    }

    public function testCommentInTable(): void
    {
        $table = new Table('table_with_comment');
        $table->addColumn('id', 'integer');
        $table->setComment('Foo with control characters \'\\');
        $this->schemaManager->dropAndCreateTable($table);

        $table = $this->schemaManager->listTableDetails('table_with_comment');
        self::assertSame('Foo with control characters \'\\', $table->getComment());
    }
}

interface ListTableColumnsDispatchEventListener
{
    public function onSchemaColumnDefinition(): void;
}

interface ListTableIndexesDispatchEventListener
{
    public function onSchemaIndexDefinition(): void;
}
