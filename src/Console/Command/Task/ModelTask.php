<?php
/**
 * The ModelTask handles creating and updating models files.
 *
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         1.2.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Console\Command\Task;

use Cake\Console\Shell;
use Cake\Core\App;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\ClassRegistry;
use Cake\Utility\Inflector;

/**
 * Task class for creating and updating model files.
 *
 * @codingStandardsIgnoreFile
 */
class ModelTask extends BakeTask {

/**
 * path to Model directory
 *
 * @var string
 */
	public $path = null;

/**
 * tasks
 *
 * @var array
 */
	public $tasks = ['DbConfig', 'Fixture', 'Test', 'Template'];

/**
 * Tables to skip when running all()
 *
 * @var array
 */
	public $skipTables = ['i18n'];

/**
 * Holds tables found on connection.
 *
 * @var array
 */
	protected $_tables = [];

/**
 * Holds the model names
 *
 * @var array
 */
	protected $_modelNames = [];

/**
 * Holds validation method map.
 *
 * @var array
 */
	protected $_validations = [];

/**
 * Override initialize
 *
 * @return void
 */
	public function initialize() {
		$this->path = current(App::path('Model'));
	}

/**
 * Execution method always used for tasks
 *
 * @return void
 */
	public function execute() {
		parent::execute();

		if (!isset($this->connection)) {
			$this->connection = 'default';
		}

		if (empty($this->args)) {
			$this->out(__d('cake_console', 'Choose a model to bake from the following:'));
			foreach ($this->listAll() as $table) {
				$this->out('- ' . $table);
			}
			return true;
		}

		if (strtolower($this->args[0]) === 'all') {
			return $this->all();
		}

		$this->generate($this->args[0]);
	}

/**
 * Generate code for the given model name.
 *
 * @param string $name The model name to generate.
 * @return void
 */
	public function generate($name) {
		$table = $this->getTable();

		$object = $this->getTableObject($name, $table);
		$associations = $this->getAssociations($object);
		$primaryKey = $this->getPrimaryKey($model);
		$displayField = $this->getDisplayField($model);
		$fields = $this->getFields($model);
		$validation = $this->getValidation($model);

		$this->bake($object, false);
		$this->bakeFixture($model, $useTable);
		$this->bakeTest($model);
	}

/**
 * Bake all models at once.
 *
 * @return void
 */
	public function all() {
		$this->listAll($this->connection, false);
		$unitTestExists = $this->_checkUnitTest();
		foreach ($this->_tables as $table) {
			if (in_array($table, $this->skipTables)) {
				continue;
			}
			$modelClass = Inflector::classify($table);
			$this->out(__d('cake_console', 'Baking %s', $modelClass));
			$object = $this->_getModelObject($modelClass, $table);
			if ($this->bake($object, false) && $unitTestExists) {
				$this->bakeFixture($modelClass, $table);
				$this->bakeTest($modelClass);
			}
		}
	}

/**
 * Get a model object for a class name.
 *
 * @param string $className Name of class you want model to be.
 * @param string $table Table name
 * @return Cake\ORM\Table Table instance
 */
	public function getTableObject($className, $table) {
		if (TableRegistry::exists($className)) {
			return TableRegistry::get($className);
		}
		return TableRegistry::get($className, [
			'name' => $className,
			'table' => $table,
			'connection' => ConnectionManager::get($this->connection)
		]);
	}

/**
 * Get the array of associations to generate.
 *
 * @return array
 */
	public function getAssociations(Table $table) {
		if (!empty($this->params['no-associations'])) {
			return [];
		}
		$assocs = [];
		$this->out(__d('cake_console', 'One moment while associations are detected.'));

		$this->listAll();

		$associations = [
			'belongsTo' => [],
			'hasMany' => [],
			'belongsToMany' => []
		];

		$associations = $this->findBelongsTo($table, $associations);
		$associations = $this->findHasMany($table, $associations);
		$associations = $this->findBelongsToMany($table, $associations);
		return $associations;
	}

/**
 * Find belongsTo relations and add them to the associations list.
 *
 * @param ORM\Table $table Database\Table instance of table being generated.
 * @param array $associations Array of in progress associations
 * @return array Associations with belongsTo added in.
 */
	public function findBelongsTo($model, $associations) {
		$schema = $model->schema();
		$primary = $schema->primaryKey();
		foreach ($schema->columns() as $fieldName) {
			$offset = strpos($fieldName, '_id');
			if (!in_array($fieldName, $primary) && $fieldName !== 'parent_id' && $offset !== false) {
				$tmpModelName = $this->_modelNameFromKey($fieldName);
				$associations['belongsTo'][] = [
					'alias' => $tmpModelName,
					'className' => $tmpModelName,
					'foreignKey' => $fieldName,
				];
			} elseif ($fieldName === 'parent_id') {
				$associations['belongsTo'][] = [
					'alias' => 'Parent' . $model->alias(),
					'className' => $model->alias(),
					'foreignKey' => $fieldName,
				];
			}
		}
		return $associations;
	}

/**
 * Find the hasMany relations and add them to associations list
 *
 * @param Model $model Model instance being generated
 * @param array $associations Array of in progress associations
 * @return array Associations with hasMany added in.
 */
	public function findHasMany($model, $associations) {
		$schema = $model->schema();
		$primaryKey = (array)$schema->primaryKey();
		$tableName = $schema->name();
		$foreignKey = $this->_modelKey($tableName);

		foreach ($this->listAll() as $otherTable) {
			$otherModel = $this->getTableObject($this->_modelName($otherTable), $otherTable);
			$otherSchema = $otherModel->schema();

			// Exclude habtm join tables.
			$pattern = '/_' . preg_quote($tableName, '/') . '|' . preg_quote($tableName, '/') . '_/';
			$possibleJoinTable = preg_match($pattern, $otherTable);
			if ($possibleJoinTable) {
				continue;
			}

			foreach ($otherSchema->columns() as $fieldName) {
				$assoc = false;
				if (!in_array($fieldName, $primaryKey) && $fieldName == $foreignKey) {
					$assoc = [
						'alias' => $otherModel->alias(),
						'className' => $otherModel->alias(),
						'foreignKey' => $fieldName
					];
				} elseif ($otherTable == $tableName && $fieldName === 'parent_id') {
					$assoc = [
						'alias' => 'Child' . $model->alias(),
						'className' => $model->alias(),
						'foreignKey' => $fieldName
					];
				}
				if ($assoc) {
					$associations['hasMany'][] = $assoc;
				}
			}
		}
		return $associations;
	}

/**
 * Find the BelongsToMany relations and add them to associations list
 *
 * @param Model $model Model instance being generated
 * @param array $associations Array of in-progress associations
 * @return array Associations with belongsToMany added in.
 */
	public function findBelongsToMany($model, $associations) {
		$schema = $model->schema();
		$primaryKey = (array)$schema->primaryKey();
		$tableName = $schema->name();
		$foreignKey = $this->_modelKey($tableName);

		$tables = $this->listAll();
		foreach ($tables as $otherTable) {
			$assocTable = null;
			$offset = strpos($otherTable, $tableName . '_');
			$otherOffset = strpos($otherTable, '_' . $tableName);

			if ($offset !== false) {
				$assocTable = substr($otherTable, strlen($tableName . '_'));
			} elseif ($otherOffset !== false) {
				$assocTable = substr($otherTable, 0, $otherOffset);
			}
			if ($assocTable && in_array($assocTable, $tables)) {
				$habtmName = $this->_modelName($assocTable);
				$associations['belongsToMany'][] = [
					'alias' => $habtmName,
					'className' => $habtmName,
					'foreignKey' => $foreignKey,
					'targetForeignKey' => $this->_modelKey($habtmName),
					'joinTable' => $otherTable
				];
			}
		}
		return $associations;
	}

/**
 * Get the display field from the model or parameters
 *
 * @param Cake\ORM\Table $model The model to introspect.
 * @return string
 */
	public function getDisplayField($model) {
		if (!empty($this->params['display-field'])) {
			return $this->params['display-field'];
		}
		return $model->displayField();
	}

/**
 * Get the primary key field from the model or parameters
 *
 * @param Cake\ORM\Table $model The model to introspect.
 * @return array The columns in the primary key
 */
	public function getPrimaryKey($model) {
		if (!empty($this->params['primary-key'])) {
			return (array)$this->params['primary-key'];
		}
		return (array)$model->primaryKey();
	}

/**
 * Get the fields from a model.
 *
 * Uses the fields and no-fields options.
 *
 * @param Cake\ORM\Table $model The model to introspect.
 * @return array The columns to make accessible
 */
	public function getFields($model) {
		if (!empty($this->params['no-fields'])) {
			return [];
		}
		if (!empty($this->params['fields'])) {
			$fields = explode(',', $this->params['fields']);
			return array_values(array_filter(array_map('trim', $fields)));
		}
		$schema = $model->schema();
		$columns = $schema->columns();
		$exclude = ['created', 'modified', 'updated', 'password', 'passwd'];
		return array_diff($columns, $exclude);
	}

/**
 * Generate default validation rules.
 *
 * @param Cake\ORM\Table $model The model to introspect.
 * @return array The validation rules.
 */
	public function getValidation($model) {
		if (!empty($this->params['no-validation'])) {
			return [];
		}
		$schema = $model->schema();
		$fields = $schema->columns();
		if (empty($fields)) {
			return false;
		}

		$skipFields = false;
		$validate = [];
		$primaryKey = (array)$schema->primaryKey();

		foreach ($fields as $fieldName) {
			$field = $schema->column($fieldName);
			$validation = $this->fieldValidation($fieldName, $field, $primaryKey);
			if (!empty($validation)) {
				$validate[$fieldName] = $validation;
			}
		}
		return $validate;
	}

/**
 * Does individual field validation handling.
 *
 * @param string $fieldName Name of field to be validated.
 * @param array $metaData metadata for field
 * @param string $primaryKey
 * @return array Array of validation for the field.
 */
	public function fieldValidation($fieldName, $metaData, $primaryKey) {
		$ignoreFields = array_merge($primaryKey, ['created', 'modified', 'updated']);
		if ($metaData['null'] == true && in_array($fieldName, $ignoreFields)) {
			return false;
		}

		if ($fieldName === 'email') {
			$rule = 'email';
		} elseif ($metaData['type'] === 'uuid') {
			$rule = 'uuid';
		} elseif ($metaData['type'] === 'string') {
			$rule = 'notEmpty';
		} elseif ($metaData['type'] === 'text') {
			$rule = 'notEmpty';
		} elseif ($metaData['type'] === 'integer') {
			$rule = 'numeric';
		} elseif ($metaData['type'] === 'float') {
			$rule = 'numeric';
		} elseif ($metaData['type'] === 'decimal') {
			$rule = 'decimal';
		} elseif ($metaData['type'] === 'boolean') {
			$rule = 'boolean';
		} elseif ($metaData['type'] === 'date') {
			$rule = 'date';
		} elseif ($metaData['type'] === 'time') {
			$rule = 'time';
		} elseif ($metaData['type'] === 'datetime') {
			$rule = 'datetime';
		} elseif ($metaData['type'] === 'inet') {
			$rule = 'ip';
		}

		$allowEmpty = false;
		if ($rule !== 'notEmpty' && $metaData['null'] === false) {
			$allowEmpty = true;
		}

		return [
			'rule' => $rule,
			'allowEmpty' => $allowEmpty,
		];
	}


/**
 * Generate a key value list of options and a prompt.
 *
 * @param array $options Array of options to use for the selections. indexes must start at 0
 * @param string $prompt Prompt to use for options list.
 * @param integer $default The default option for the given prompt.
 * @return integer Result of user choice.
 */
	public function inOptions($options, $prompt = null, $default = null) {
		$valid = false;
		$max = count($options);
		while (!$valid) {
			$len = strlen(count($options) + 1);
			foreach ($options as $i => $option) {
				$this->out(sprintf("%${len}d. %s", $i + 1, $option));
			}
			if (empty($prompt)) {
				$prompt = __d('cake_console', 'Make a selection from the choices above');
			}
			$choice = $this->in($prompt, null, $default);
			if (intval($choice) > 0 && intval($choice) <= $max) {
				$valid = true;
			}
		}
		return $choice - 1;
	}

/**
 * Handles interactive baking
 *
 * @return boolean
 * @throws \Exception Will throw this until baking models works
 */
	protected function _interactive() {
		$this->hr();
		$this->out(__d('cake_console', "Bake Model\nPath: %s", $this->getPath()));
		$this->hr();
		$this->interactive = true;

		$primaryKey = 'id';
		$validate = $associations = [];

		if (empty($this->connection)) {
			$this->connection = $this->DbConfig->getConfig();
		}
		throw new \Exception('Baking models does not work yet.');

		$currentModelName = $this->getName();
		$useTable = $this->getTable($currentModelName);
		$db = ConnectionManager::getDataSource($this->connection);
		$fullTableName = $db->fullTableName($useTable);
		if (!in_array($useTable, $this->_tables)) {
			$prompt = __d('cake_console', "The table %s doesn't exist or could not be automatically detected\ncontinue anyway?", $useTable);
			$continue = $this->in($prompt, ['y', 'n']);
			if (strtolower($continue) === 'n') {
				return false;
			}
		}

		$tempModel = new Model(['name' => $currentModelName, 'table' => $useTable, 'ds' => $this->connection]);

		$knownToExist = false;
		try {
			$fields = $tempModel->schema(true);
			$knownToExist = true;
		} catch (\Exception $e) {
			$fields = [$tempModel->primaryKey];
		}
		if (!array_key_exists('id', $fields)) {
			$primaryKey = $this->findPrimaryKey($fields);
		}

		if ($knownToExist) {
			$displayField = $tempModel->hasField(['name', 'title']);
			if (!$displayField) {
				$displayField = $this->findDisplayField($tempModel->schema());
			}

			$prompt = __d('cake_console', "Would you like to supply validation criteria \nfor the fields in your model?");
			$wannaDoValidation = $this->in($prompt, ['y', 'n'], 'y');
			if (array_search($useTable, $this->_tables) !== false && strtolower($wannaDoValidation) === 'y') {
				$validate = $this->doValidation($tempModel);
			}

			$prompt = __d('cake_console', "Would you like to define model associations\n(hasMany, hasOne, belongsTo, etc.)?");
			$wannaDoAssoc = $this->in($prompt, ['y', 'n'], 'y');
			if (strtolower($wannaDoAssoc) === 'y') {
				$associations = $this->doAssociations($tempModel);
			}
		}

		$this->out();
		$this->hr();
		$this->out(__d('cake_console', 'The following Model will be created:'));
		$this->hr();
		$this->out(__d('cake_console', "Name:       %s", $currentModelName));

		if ($this->connection !== 'default') {
			$this->out(__d('cake_console', "DB Config:  %s", $this->connection));
		}
		if ($fullTableName !== Inflector::tableize($currentModelName)) {
			$this->out(__d('cake_console', 'DB Table:   %s', $fullTableName));
		}
		if ($primaryKey !== 'id') {
			$this->out(__d('cake_console', 'Primary Key: %s', $primaryKey));
		}
		if (!empty($validate)) {
			$this->out(__d('cake_console', 'Validation: %s', print_r($validate, true)));
		}
		if (!empty($associations)) {
			$this->out(__d('cake_console', 'Associations:'));
			$assocKeys = ['belongsTo', 'hasOne', 'hasMany', 'hasAndBelongsToMany'];
			foreach ($assocKeys as $assocKey) {
				$this->_printAssociation($currentModelName, $assocKey, $associations);
			}
		}

		$this->hr();
		$looksGood = $this->in(__d('cake_console', 'Look okay?'), ['y', 'n'], 'y');

		if (strtolower($looksGood) === 'y') {
			$vars = compact('associations', 'validate', 'primaryKey', 'useTable', 'displayField');
			$vars['useDbConfig'] = $this->connection;
			if ($this->bake($currentModelName, $vars)) {
				if ($this->_checkUnitTest()) {
					$this->bakeFixture($currentModelName, $useTable);
					$this->bakeTest($currentModelName, $useTable, $associations);
				}
			}
		} else {
			return false;
		}
	}

/**
 * Handles behaviors
 *
 * @param Model $model
 * @return array Behaviors
 */
	public function doActsAs($model) {
		if (!$model instanceof Model) {
			return false;
		}
		$behaviors = [];
		$fields = $model->schema(true);
		if (empty($fields)) {
			return [];
		}

		if (isset($fields['lft']) && $fields['lft']['type'] === 'integer' &&
			isset($fields['rght']) && $fields['rght']['type'] === 'integer' &&
			isset($fields['parent_id'])) {
			$behaviors[] = 'Tree';
		}
		return $behaviors;
	}

/**
 * Assembles and writes a Model file.
 *
 * @param string|object $name Model name or object
 * @param array|boolean $data if array and $name is not an object assume bake data, otherwise boolean.
 * @return string
 */
	public function bake($name, $data = []) {
		if ($name instanceof Model) {
			if (!$data) {
				$data = [];
				$data['associations'] = $this->doAssociations($name);
				$data['validate'] = $this->doValidation($name);
				$data['actsAs'] = $this->doActsAs($name);
			}
			$data['primaryKey'] = $name->primaryKey;
			$data['useTable'] = $name->table;
			$data['useDbConfig'] = $name->useDbConfig;
			$data['name'] = $name = $name->name;
		} else {
			$data['name'] = $name;
		}

		$defaults = [
			'associations' => [],
			'actsAs' => [],
			'validate' => [],
			'primaryKey' => 'id',
			'useTable' => null,
			'useDbConfig' => 'default',
			'displayField' => null
		];
		$data = array_merge($defaults, $data);

		$pluginPath = '';
		if ($this->plugin) {
			$pluginPath = $this->plugin . '.';
		}

		$this->Template->set($data);
		$this->Template->set([
			'plugin' => $this->plugin,
			'pluginPath' => $pluginPath
		]);
		$out = $this->Template->generate('classes', 'model');

		$path = $this->getPath();
		$filename = $path . $name . '.php';
		$this->out("\n" . __d('cake_console', 'Baking model class for %s...', $name), 1, Shell::QUIET);
		$this->createFile($filename, $out);
		ClassRegistry::flush();
		return $out;
	}

/**
 * Outputs the a list of possible models or controllers from database
 *
 * @param string $useDbConfig Database configuration name
 * @return array
 */
	public function listAll() {
		if (!empty($this->_tables)) {
			return $this->_tables;
		}

		$this->_modelNames = [];
		$this->_tables = $this->_getAllTables();
		foreach ($this->_tables as $table) {
			$this->_modelNames[] = $this->_modelName($table);
		}
		return $this->_tables;
	}

/**
 * Get an Array of all the tables in the supplied connection
 * will halt the script if no tables are found.
 *
 * @return array Array of tables in the database.
 * @throws InvalidArgumentException When connection class
 *   has a schemaCollection method.
 */
	protected function _getAllTables() {
		$tables = [];
		$db = ConnectionManager::get($this->connection);
		if (!method_exists($db, 'schemaCollection')) {
			$this->err(__d(
				'cake_console',
				'Connections need to implement schemaCollection() to be used with bake.'
			));
			return $this->_stop();
		}
		$schema = $db->schemaCollection();
		$tables = $schema->listTables();
		if (empty($tables)) {
			$this->err(__d('cake_console', 'Your database does not have any tables.'));
			return $this->_stop();
		}
		sort($tables);
		return $tables;
	}

/**
 * Get the table name for the model being baked.
 *
 * Uses the `table` option if it is set.
 *
 * @return string.
 */
	public function getTable() {
		if (isset($this->params['table'])) {
			return $this->params['table'];
		}
		return Inflector::tableize($this->args[0]);
	}

/**
 * Gets the option parser instance and configures it.
 *
 * @return ConsoleOptionParser
 */
	public function getOptionParser() {
		$parser = parent::getOptionParser();

		$parser->description(
			__d('cake_console', 'Bake models.')
		)->addArgument('name', [
			'help' => __d('cake_console', 'Name of the model to bake. Can use Plugin.name to bake plugin models.')
		])->addSubcommand('all', [
			'help' => __d('cake_console', 'Bake all model files with associations and validation.')
		])->addOption('plugin', [
			'short' => 'p',
			'help' => __d('cake_console', 'Plugin to bake the model into.')
		])->addOption('theme', [
			'short' => 't',
			'help' => __d('cake_console', 'Theme to use when baking code.')
		])->addOption('connection', [
			'short' => 'c',
			'help' => __d('cake_console', 'The connection the model table is on.')
		])->addOption('force', [
			'short' => 'f',
			'help' => __d('cake_console', 'Force overwriting existing files without prompting.')
		])->addOption('table', [
			'help' => __d('cake_console', 'The table name to use if you have non-conventional table names.')
		])->addOption('no-entity', [
			'boolean' => true,
			'help' => __d('cake_console', 'Disable generating an entity class.')
		])->addOption('no-table', [
			'boolean' => true,
			'help' => __d('cake_console', 'Disable generating a table class.')
		])->addOption('no-validation', [
			'boolean' => true,
			'help' => __d('cake_console', 'Disable generating validation rules.')
		])->addOption('no-associations', [
			'boolean' => true,
			'help' => __d('cake_console', 'Disable generating associations.')
		])->addOption('no-fields', [
			'boolean' => true,
			'help' => __d('cake_console', 'Disable generating accessible fields in the entity.')
		])->addOption('fields', [
			'help' => __d('cake_console', 'A comma separated list of fields to make accessible.')
		])->addOption('primary-key', [
			'help' => __d('cake_console', 'The primary key if you would like to manually set one.')
		])->addOption('display-field', [
			'help' => __d('cake_console', 'The displayField if you would like to choose one.')
		])->addOption('no-test', [
			'help' => __d('cake_console', 'Do not generate a test case skeleton.')
		])->addOption('no-fixture', [
			'help' => __d('cake_console', 'Do not generate a test fixture skeleton.')
		])->epilog(
			__d('cake_console', 'Omitting all arguments and options will list ' .
				'the table names you can generate models for')
		);

		return $parser;
	}

/**
 * Interact with FixtureTask to automatically bake fixtures when baking models.
 *
 * @param string $className Name of class to bake fixture for
 * @param string $useTable Optional table name for fixture to use.
 * @return void
 * @see FixtureTask::bake
 */
	public function bakeFixture($className, $useTable = null) {
		if (!empty($this->params['no-fixture'])) {
			return;
		}
		$this->Fixture->connection = $this->connection;
		$this->Fixture->plugin = $this->plugin;
		$this->Fixture->bake($className, $useTable);
	}

/**
 * Assembles and writes a unit test file
 *
 * @param string $className Model class name
 * @return string
 */
	public function bakeTest($className) {
		if (!empty($this->params['no-test'])) {
			return;
		}
		$this->Test->plugin = $this->plugin;
		$this->Test->connection = $this->connection;
		return $this->Test->bake('Model', $className);
	}


}