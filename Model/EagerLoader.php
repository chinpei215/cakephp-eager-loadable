<?php

class EagerLoader extends Model {

	public $useTable = false;

	private $settings = [];

	private $containOptions = [
		'conditions' => 1,
		'fields' => 1,
		'order' => 1,
		'limit' => 1,
	];

	public function isVirtualField($field) {
		return true;
	}

/**
 * 
 *
 * @param $model
 * @param $query
 *
 * @return array
 */
	public function transformQuery(Model $model, $query) {
		static $id = 0;
		$this->id = (++$id);

		$contain = $this->reformatContain($query['contain']);
		foreach ($contain['contain'] as $key => $val) {
			$this->parseContain($model, $key, $val, [
				'root' => $model->alias, 
				'aliasPath' => $model->alias,
				'propertyPath' => '',
			]);
		}

		$query = $this->attachAssociations($model, $model->alias, $query);
		$query['fields'] = array_merge($query['fields'], ['(' . $this->id . ') AS EagerLoader__id']);

		return $query;
	}

/**
 * 
 *
 * @param $model
 * @param $path
 * @param $query
 *
 * @return array
 */
	private function attachAssociations(Model $model, $path, array $query) {
		$db = $model->getDataSource();

		$query = $this->normalizeQuery($model, $query);
		$metas =& $this->settings[$this->id][$path];

		if ($metas) {
			foreach ($metas as $alias => $meta) {
				extract($meta);
				if ($external) {
					$query = $this->addKeyField($parent, $query, "$parentAlias.$parentKey");
				} else {
					$joinType = 'LEFT';
					if ($belong) {
						$field = $parent->schema($parentKey);
						$joinType = ($field['null'] ? 'LEFT' : 'INNER');
					}

					$query = $this->buildJoinQuery($target, $query, $joinType, ["$parentAlias.$parentKey" => "$alias.$targetKey"], $options);
				}
			}
		}

		$query['recursive'] = -1;
		$query['contain'] = false;

		return $query;
	}

/**
 * 
 * @param string $path
 * @param array $results
 *
 * @return array
 */
	public function loadExternal($path, array $results, $primary = true) {
		$metas =& $this->settings[$this->id][$path];

		if ($metas) {
			$metas = Hash::sort($metas, '{s}.propertyPath', 'desc');

			foreach ($metas as $alias => $meta) {
				extract($meta);

				$assocResults = [];

				$assocAlias = $alias;
				$assocKey = $targetKey;

				if ($external) {
					$db = $target->getDataSource();

					$options = $this->attachAssociations($target, $aliasPath, $options);
					if ($has && $belong) {
						$assocAlias = $habtmAlias;
						$assocKey = $habtmParentKey;

						$options = $this->buildJoinQuery($habtm, $options, 'INNER', [
							"$alias.$targetKey" => "$habtmAlias.$habtmTargetKey",
						], $options);
					}

					$options = $this->addKeyField($target, $options, "$assocAlias.$assocKey");

					$ids = Hash::extract($results, "{n}.$parentAlias.$parentKey");
					$ids = array_unique($ids);

					if (empty($options['limit'])) {
						$options['conditions'] = array_merge($options['conditions'], ["$assocAlias.$assocKey" => $ids]);
						$assocResults = $db->read($target, $options);
					} else {
						foreach ($ids as $id) {
							$options['conditions']["$assocAlias.$assocKey"] = $id;
							$assocResults = array_merge($db->read($target, $options), $assocResults);
						}
					}

					// Triggers afterFind for the external primary model.
					$this->$alias = $target; // Hack for DboSource::_filterResults()
					$db->dispatchMethod('_filterResultsInclusive', [&$assocResults, $this, [$alias]]);
				} else {
					foreach ($results as &$result) {
						$assocResults[] = [ $alias => $result[$alias] ];
						unset($result[$alias]);
					}
				}

				$assocResults = $this->loadExternal($aliasPath, $assocResults, false);

				foreach ($results as &$result) {
					$assoc = [];

					foreach ($assocResults as $assocResult) {
						if ($result[$parentAlias][$parentKey] == $assocResult[$assocAlias][$assocKey]) {
							if ($has && $belong) {
								$assoc[] = $assocResult[$alias] + [$assocAlias => $assocResult[$assocAlias]];
							} else {
								$assoc[] = $assocResult[$alias];
							}
						}
					}

					if (!$many) {
						$assoc = $assoc ? current($assoc) : [];
					}

					$result = Hash::insert($result, $propertyPath, $assoc + (array)Hash::get($result, $propertyPath));
				}
				unset($result);
			}
		}

		if ($primary) {
			unset($this->settings[$this->id]);
		}
		
		return $results;
	}

/**
 * 
 *
 * @param $contain
 *
 * @return array
 */
	private function reformatContain($contain) {
		$result = [
			'options' => [],
			'contain' => [],
		];

		$contain = (array)$contain;
		foreach ($contain as $key => $val) {
			if (is_int($key)) {
				$key = $val;
				$val = [];
			}

			if (!isset($this->containOptions[$key])) {
				if (strpos($key, '.') !== false) {
					$expanded = Hash::expand([$key => $val]);
					list($key, $val) = each($expanded);
				}
				$ref =& $result['contain'][$key];
				$ref = Hash::merge((array)$ref, $this->reformatContain($val));
			} else {
				$result['options'][$key] = $val;
			}
		}

		return $result;
	}

/**
 * 
 *
 * @param Model $model
 * @param array $query
 *
 * @return 
 */
	private function normalizeQuery(Model $model, array $query) {
		$db = $model->getDataSource();

		$query += [
			'fields' => [],
			'conditions' => []
		];

		if (!$query['fields']) {
			$query['fields'] = $db->fields($model);
		} else {
			$query['fields'] = array_map([$db, 'name'], (array)$query['fields']);
		}

		$query['conditions'] = (array)$query['conditions'];

		return $query;
	}

/**
 * 
 *
 * @param $query
 * @param $target
 * @param $joinType
 * @param $options
 *
 * @return 
 */
	private function buildJoinQuery(Model $target, array $query, $joinType, array $keys, array $options) {
		$db = $target->getDataSource();

		$options = $this->normalizeQuery($target, $options);
		$query['fields'] = array_merge($query['fields'], $options['fields']);

		foreach ($keys as $lhs => $rhs) {
			$query = $this->addKeyField($target, $query, $lhs);
			$query = $this->addKeyField($target, $query, $rhs);
			$options['conditions'][$lhs] = $db->identifier($rhs);
		}

		$query['joins'][] = [
			'type' => $joinType,
			'table' => $db->fullTableName($target),
			'alias' => $target->alias,
			'conditions' => $options['conditions'],
		];
		return $query;
	}

/**
 * 
 *
 * @param $query
 * @param $key
 *
 * @return 
 */
	private function addKeyField(Model $model, $query, $key) {
		$db = $model->getDataSource();

		$quotedKey = $db->name($key);
		if (!in_array($key, $query['fields'], true) && !in_array($quotedKey, $query['fields'], true)) {
			$query['fields'][] = $quotedKey;
		}
		return $query;
	}

/**
 * 
 *
 * @param Model $parent
 * @param string $alias
 * @param array $contain
 * @param array $paths
 *
 * @return array
 */
	private function parseContain(Model $parent, $alias, array $contain, array $paths) {
		$map =& $this->settings[$this->id];

		$aliasPath = $paths['aliasPath'] . '.' . $alias;
		$propertyPath = ($paths['propertyPath'] ? $paths['propertyPath'] . '.' : '') . $alias;

		$types = $parent->getAssociated();
		if (!isset($types[$alias])) {
			throw new InvalidArgumentException(sprintf('Model "%s" is not associated with model "%s"', $parent->alias, $alias), E_USER_WARNING);
		}

		$parentAlias = $parent->alias;
		$target = $parent->$alias;
		$type = $types[$alias];
		$relation = $parent->{$type}[$alias];
		$options = $contain['options'];

		$has = (stripos($type, 'has') !== false);
		$many = (stripos($type, 'many') !== false);
		$belong = (stripos($type, 'belong') !== false);

		if ($has && $belong) {
			$parentKey = $parent->primaryKey;
			$targetKey = $target->primaryKey;
			$habtmAlias = $relation['with'];
			$habtm = $parent->$habtmAlias;
			$habtmParentKey = $relation['foreignKey'];
			$habtmTargetKey = $relation['associationForeignKey'];
		} elseif ($has) {
			$parentKey = $parent->primaryKey;
			$targetKey = $relation['foreignKey'];
		} else {
			$parentKey = $relation['foreignKey'];
			$targetKey = $target->primaryKey;
		}

		$tmp = explode($paths['root'], '.');
		$rootAlias = end($tmp);
		if ($many || $alias === $rootAlias || isset($map[$paths['root']][$alias])) {
			$external = true;
			$paths['root'] = $aliasPath;
			$paths['propertyPath'] = $alias;
			$path = $paths['aliasPath'];
		} else {
			$external = false;
			$paths['propertyPath'] = $propertyPath;
			$path = $paths['root'];
		}

		$map[$path][$alias] = compact(
			'parent', 'target',
			'parentAlias', 'parentKey',
			'targetKey', 'aliasPath', 'propertyPath',
			'options', 'has', 'many', 'belong', 'external',
			'habtm', 'habtmAlias', 'habtmParentKey', 'habtmTargetKey'
		);

		$paths['aliasPath'] = $aliasPath;
		foreach ($contain['contain'] as $key => $val) {
			$this->parseContain($target, $key, $val, $paths);
		}

		return $map;
	}
}
