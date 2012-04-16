<?php
/**
 * Provides Counter Cache and Under Counter Cache (for hierarchical models)
 * behavior with scope for HABTM associations e.g. Post habtm Tag,Category where
 * Tag has a post_count and Category is a hierarchical and has post_count and
 * under_post_count fields.
 *
 * Under Counter Cache is a Counter Cache that counts all distinct records
 * assigned to that node explicitly, or any of it's children. This is useful for
 * figuring out how many posts are assigned to a category of one of it's
 * children, which you can use to print out a nested list of categories with the
 * number of posts next to them.
 *
 * Usage example:
 * class Post extends AppModel {
 *   var $name = 'Post';
 *   var $actsAs = array('HabtmCounterCache.HabtmCounterCache');
 *   var $hasAndBelongsToMany = array('Category', 'Tag');
 * }
 *
 * @author Neil Crookes <neil@neilcrookes.com>
 * @link http://www.neilcrookes.com
 * @copyright (c) 2010 Neil Crookes
 * @license MIT License - http://www.opensource.org/licenses/mit-license.php
 * @link http://github.com/neilcrookes
 */
class HabtmCounterCacheBehavior extends ModelBehavior {

  /**
   * Array in the form:
   *
   *     array(
   *       $model->alias => array(
   *         $model->id => array(
   *           $habtmAlias => array(1,2,3,...) // $habtmAlias is the alias of the habtm model, e.g. if $model is Post, $habtmAlias is Tag
   *         )
   *       )
   *     )
   *
   * Used to store the ids of the habtm related models whose counterCache fields
   * need updating in either afterSave or afterDelete.
   *
   * @var array
   */
  protected $_habtmIds = array();

  /**
   * Populates the settings property of the behavior in an array in the form:
   *
   *     array(
   *       $model->alias => array(
   *         $habtmAlias => array(
   *           'counterCache' => '<counterCache field name>'
   *           'counterScope' => array('field' => 'value') // Usual CakePHP condition
   *           'underCounterCache' => '<counterTree field name>'
   *         ),
   *       ),
   *     )
   *
   * @param AppModel $model
   * @param array $config Configuration is very flexible, for example:
   *        - Just attach and it will do counter caching for all hatbm
   *        associated models that have the counterCache field. E.g.
   *
   *            var $actsAs = array('HabtmCounterCache.HabtmCounterCache');
   *
   *        - Specify counterCache, counterScope and/or underCounterCache keys
   *        in the configuration options when you attach the behavior for these
   *        settings to be applied to all habtm associations. E.g.
   *
   *            var $actsAs = array(
   *              'HabtmCounterCache.HabtmCounterCache' => array(
   *                'counterScope' => array('active' => 1)
   *              ),
   *            );
   *
   *        - Introduce habtm association specific counterCache and counterScope
   *        settings by using the habtm alias as the key E.g.
   *
   *            var $actsAs = array(
   *              'HabtmCounterCache.HabtmCounterCache' => array(
   *                'Tag' => array(
   *                  'counterCache' => 'weight'
   *                )
   *              ),
   *            );
   */
  public function setup(&$model, $config = null) {

    // Work out the default names of what we expect the counter cache and under
    // counter cache fields to be. These will be overridden if specified in the
    // config.
    $defaultCounterCacheField = Inflector::underscore($model->alias) . '_count';
    $defaultUnderCounterCacheField = 'under_' . $defaultCounterCacheField;

    // Set up the default settings for this model. Default counterCache field is
    // post_count for Post model, no counterScope.
    $defaults = array(
      'counterCache' => $defaultCounterCacheField,
      'counterScope' => null,
      'underCounterCache' => $defaultUnderCounterCacheField,
    );

    // Get the settings for all habtm associations, if set.
    $allHabtmSettings = $this->_config2settings($config);

    $allHabtmSettings = array_merge($defaults, $allHabtmSettings);

    // Iterate through the habtms of the model, assigning the settings to the
    // settings property of the behavior
    foreach ($model->hasAndBelongsToMany as $habtmAlias => $habtmAssocData) {

      $habtmSpecificSettings = array();

      // Check whether habtm specific settings have been set for this alias
      if (isset($config[$habtmAlias])) {
        if ($config[$habtmAlias] == false) {
          continue;
        } else {
          $habtmSpecificSettings = $this->_config2settings($config[$habtmAlias]);
        }
      // Check whether habtm specific settings have been set for this habtm's
      // class name (note, you may have 2 assocs using the same class name)
      } elseif (isset($config[$habtmAssocData['className']])) {
        if ($config[$habtmAssocData['className']] == false) {
          continue;
        } else {
          $habtmSpecificSettings = $this->_config2settings($config[$habtmAssocData['className']]);
        }
      }

      // The behavior needs to know the className, joinTable, foreignKey and
      // associationForeignKey of the assoc later, so may as well grab them now.
      $habtmSpecificSettings += array_intersect_key($habtmAssocData, array_flip(array('className', 'joinTable', 'foreignKey', 'associationForeignKey')));

      // It also needs to know the joinModel, so may as well determine that now
      $habtmSpecificSettings['joinModel'] = Inflector::camelize(Inflector::singularize($habtmSpecificSettings['joinTable']));

      // Merge any habtm specific settings for the current association with the
      // defaults and the config for all associations
      $habtmSpecificSettings = array_merge($allHabtmSettings, $habtmSpecificSettings);

      // Verify the counterCache and underCounterCache fields specified or
      // determined from the defaults actually exist on the habtm model.
      $habtmSchema = $model->$habtmAlias->schema();
      if (!array_key_exists($habtmSpecificSettings['counterCache'], $habtmSchema)) {
        $habtmSpecificSettings['counterCache'] = false;
      }
      if (!array_key_exists('lft', $habtmSchema) || !array_key_exists('rght', $habtmSchema) || !array_key_exists($habtmSpecificSettings['underCounterCache'], $habtmSchema)) {
        $habtmSpecificSettings['underCounterCache'] = false;
      }
      if (!$habtmSpecificSettings['counterCache'] && !$habtmSpecificSettings['underCounterCache']) {
        continue;
      }

      // Store the merged settings in the behavior's settings property indexed
      // by the model->alias and the habtmAlias
      $this->settings[$model->alias][$habtmAlias] = $habtmSpecificSettings;

    }

  }

  /**
   * Attempts to normalise the config and produce a standard structure for the
   * settings that apply to either all habtm associations or just one.
   *
   * If config is a string, it's assumed the value is the counterCache field
   * name. If it's an array, only the elements with keys mayching counterCache,
   * counterScope and underCounterCache are actually used.
   *
   * @param mixed $config
   * @return array
   */
  protected function _config2settings($config) {

    $settings = array();

    // If a string, assume counterCache field name
    if (is_string($config)) {
      $settings['counterCache'] = $config;
    // If array, use the counter Cache, Scope and Tree keys
    } elseif (is_array($config)) {
      if (isset($config['counterCache'])) {
        $settings['counterCache'] = $config['counterCache'];
      }
      if (isset($config['counterScope'])) {
        $settings['counterScope'] = $config['counterScope'];
      }
      if (isset($config['underCounterCache'])) {
        $settings['underCounterCache'] = $config['underCounterCache'];
      }
    }
    return $settings;
  }

  /**
   * Called automatically before Model::save()
   *
   * If inserting, there were no previous habtm associated records that may no
   * longer be associated, so just return.
   *
   * If updating, there may have been previous habtm associated records that are
   * no longer associated, e.g. you removed a tag, so you need to identify all
   * previously associated records and store them for after save where they will
   * each have their counts recalculated.
   *
   * @param AppModel $model
   * @return boolean Always true
   */
  public function beforeSave(&$model) {

    // If no model->id, inserting, so return
    if (!$model->id) {
      return true;
    }

    $this->_setOldHabtmIds($model);

    return true;

  }

  /**
   * Adds current associated record ids (from the db) to the _habtmIds property
   * for each habtm association in the settings
   *
   * @param AppModel $model
   */
  protected function _setOldHabtmIds(&$model) {
    foreach ($this->settings[$model->alias] as $habtmAlias => $settings) {
      // Instantiate a model for the join table, e.g. PostsTag
      $JoinModelObj = ClassRegistry::init($settings['joinModel']);
      // Get ids of the current associated habtm records e.g. list of tag_id's
      $oldHabtmIds = $JoinModelObj->find('list', array(
        'fields' => array($settings['associationForeignKey'], $settings['associationForeignKey']),
        'conditions' => array($settings['foreignKey'] => $model->id)
      ));
      // Add tag_ids to _habtmsIds property
      $this->_habtmIds[$model->alias][$model->id][$habtmAlias] = $oldHabtmIds;
    }
  }

  /**
   * Called automatically after Model::save()
   *
   * Adds new habtm ids to the list of ids of associated habtm records to update
   * the counters for, then triggers the update.
   *
   * @param AppModel $model
   * @param boolean $created
   * @return boolean Always true
   */
  public function afterSave(&$model, $created) {

    $this->_setNewHabtmIds($model);

    $this->_updateCounterCache($model);

    return true;

  }

  /**
   * Updates the _habtmIds property with the new habtm ids. E.g. Post is created
   * with some tags or Post is edited ang tags have changed.
   *
   * @param AppModel $model
   */
  protected function _setNewHabtmIds($model) {

    // Iterate through the habtm associations
    foreach ($this->settings[$model->alias] as $habtmAlias => $settings) {

      // If habtm alias key is not set in model->data, the associated habtm ids
      // are not changing, but the scope of the record may be, so we still need
      // need to leave the old ones in the _habtmIds property and re-calculate
      // any counts.
      if (!isset($model->data[$habtmAlias][$habtmAlias])) {
        continue;
      }

      // If there are no old habtm ids, add the new ones to the _habtmIds
      // property
      if (empty($this->_habtmIds[$model->alias][$model->id][$habtmAlias])) {
        $this->_habtmIds[$model->alias][$model->id][$habtmAlias] = $model->data[$habtmAlias][$habtmAlias];
        continue;
      }

      // If there are old habtm ids merge them with the new ones
      $this->_habtmIds[$model->alias][$model->id][$habtmAlias] = array_unique(array_merge(
        $this->_habtmIds[$model->alias][$model->id][$habtmAlias],
        $model->data[$habtmAlias][$habtmAlias]
      ));

    }
  }

  /**
   * Called automatically before Model::delete()
   *
   * If deleting a record that has associated habtm records, the habtm records
   * counter caches will need re-calculating, so identify them. E.g. get the
   * tag_ids of the Tags that the Post being deleted was tagged with.
   *
   * @param AppModel $model
   * @return boolean Always true
   */
  function beforeDelete(&$model) {

    $this->_setOldHabtmIds($model);

    return true;

  }

  /**
   * Trigger the update of the counts of the relevant associated habtm model
   * records, e.g. the Tags of the Post that was just deleted.
   *
   * @param AppModel $model
   */
  function afterDelete(&$model) {

    $this->_updateCounterCache($model);

  }

  /**
   * Updates the counter cache and/or under counter cache for each associated
   * habtm model's recordsidentified in the _habtmIds property
   *
   * @param AppModel $model
   */
  function _updateCounterCache(&$model) {

    // Do one habtm associated model at a time
    foreach ($this->settings[$model->alias] as $habtmAlias => $settings) {

      // If there are no ids for this habtm to update the counts for, move on
      if (!isset($this->_habtmIds[$model->alias][$model->id][$habtmAlias])) {
        continue;
      }

      // We need access to the datasource of the HABTM model directly since
      // we're building and executing raw statements. This is done inside the
      // current loop for each habtm model in case they have different
      // datasources.
      $ds = $model->$habtmAlias->getDataSource();

      // Initialise the update query, we'll add joins and fields below
      $updateQuery = array(
        'table' => $model->$habtmAlias->table,
        'alias' => '`'.$habtmAlias.'`',
        'order' => null,
        'limit' => null,
        'group' => null,
        'conditions' => null,
        'joins' => null
      );

      // Add the parts of the statement for updating the counter cache field
      if ($settings['counterCache']) {

        // First build the query that will be the subquery for calculating the
        // counter cache value.
        $counterQuery = array(
          'fields' => array('COUNT(*)'),
          'table' => $settings['joinTable'],
          'alias' => $settings['joinModel'],
          'conditions' => array(
            $settings['joinModel'] . '.' . $settings['associationForeignKey'] . ' = ' . $habtmAlias . '.' . $model->$habtmAlias->primaryKey,
          ),
          'order' => null,
          'limit' => null,
          'group' => null,
          'joins' => array()
        );

        // Add conditions to the counter cache sub query if there is counter
        // scope conditions. Since these exist on the main model, we have to
        // join to that one.
        if ($settings['counterScope']) {

          $counterQuery['joins'][] = array(
            'type' => 'INNER',
            'alias' => $model->alias,
            'table' => $model->table,
            'conditions' => array_merge(array(
              $settings['joinModel'] . '.' . $settings['foreignKey'] . '=' . $model->alias . '.' . $model->primaryKey
            ), $settings['counterScope']),
          );

        }

        // Convert the counter cache query array to a string so that it can be
        // added to the fields key in the main update query.
        $JoinModelObj = ClassRegistry::init($settings['joinModel']);
        $counterStatement = $ds->buildStatement($counterQuery, $JoinModelObj);

        $updateQuery['fields'][] = $settings['counterCache'] . ' = (' . $counterStatement . ')';

      }

      // Add in the under counter cache component to the update query. This
      // involves an additional join from the main table in the main update
      // query to a derived table defined by a sub-select.
      if ($settings['underCounterCache']) {

        // Build the select statement that becomes the sub query that the main
        // table is joined to. This one is quite a beast as it handles the
        // counting of the posts both in and under each category.
        $underCounterQuery = array(
          'fields' => array($habtmAlias . '.' . $model->$habtmAlias->primaryKey, 'COUNT(DISTINCT ' . $settings['foreignKey'] . ') AS under_count'),
          'table' => $model->$habtmAlias->table,
          'alias' => $habtmAlias,
          'conditions' => null,
          'order' => null,
          'limit' => null,
          'group' => array(
            $habtmAlias . '.' . $model->$habtmAlias->primaryKey,
          ),
          'joins' => array(
            array(
              'type' => 'LEFT',
              'alias' => $habtmAlias . '2',
              'table' => $model->$habtmAlias->table,
              'conditions' => array(
                $habtmAlias . '.lft <= ' . $habtmAlias . '2.lft',
                $habtmAlias . '.rght >= ' . $habtmAlias . '2.rght',
              )
            ),
            array(
              'type' => 'INNER',
              'alias' => $settings['joinModel'],
              'table' => $settings['joinTable'],
              'conditions' => array(
                $habtmAlias . '2.' . $model->$habtmAlias->primaryKey . ' = ' . $settings['joinModel'] . '.' . $settings['associationForeignKey'],
              )
            ),
          )
        );

        // Add conditions to the under counter cache sub query if there is
        // counter scope conditions. Since these exist on the main model, we
        // have to join to that one.
        if ($settings['counterScope']) {

          $underCounterQuery['joins'][] = array(
            'type' => 'INNER',
            'alias' => $model->alias,
            'table' => $model->table,
            'conditions' => array(
              $settings['joinModel'] . '.' . $settings['foreignKey'] . '=' . $model->alias . '.' . $model->primaryKey
            ),
          );

          $underCounterQuery['conditions'] = $settings['counterScope'];

        }

        // Convert the under counter cache query array to a string so that it
        // can be added to the fields key in the main update query.
        $underCounterStatement = $ds->buildStatement($underCounterQuery, $model->$habtmAlias);

        // Join up the main table and the derived table defined by the sub query
        // above in the main update query
        $updateQuery['joins'] = $ds->buildJoinStatement(array(
          'type' => 'LEFT',
          'alias' => 'x',
          'table' => '(' . $underCounterStatement . ')',
          'conditions' => array(
            $habtmAlias . '.' . $model->$habtmAlias->primaryKey . ' = x.' . $model->$habtmAlias->primaryKey,
          )
        ));

        // Add the under counter cache update field component to the update
        // query.
        $updateQuery['fields'][] = $settings['underCounterCache'] . ' = x.under_count';

      }

      // Wire up the final parts of the update query, render it and execute it.
      $updateQuery['fields'] = implode(', ', $updateQuery['fields']);
      $updateStatement = $ds->renderStatement('UPDATE', $updateQuery);
      $ds->execute($updateStatement);

    }

  }

  /**
   * Returns an array with each item being another array with keys for text, id
   * url, selected-parent, selected and children, which may contain the same
   * again. Essentially it's a datastructure that represents a hierarchical menu
   * with items being the displayfield of the habtm model, followed by the
   * under counter cache value in brackets. E.g.
   *
   *     array(
   *       array(
   *         'text' => 'Category 1 (10)',
   *         'id' => 1,
   *         'url' => array('slug' => 'cat_1'),
   *         'selected-parent' => true,
   *         'children' => array(
   *           array(
   *             'text' => 'Category 1.1 (5)',
   *             'id' => 2,
   *             'url' => array('slug' => 'cat_1_1'),
   *             'selected-parent' => false,
   *             'children' => array()))))
   *
   * @param AppModel $model
   * @param string $habtmAlias E.g. 'Category'
   * @param array $options Optional array with possible keys for 'url' and/or
   *        'selected'.
   *        - 'url' An array with keys for the keys you want in the 'url'
   *        element of each item in the results. Values are the names of the
   *        fields whose value will be the value in the url element of each item
   *        in the results.
   *        - 'selected' An array with key identifying the field to check for
   *        the selected item and value being the selected value to check.
   * @return array
   */
  public function getMenuWithUnderCounts(&$model, $habtmAlias, $options = array()) {

    // Set the default url options, which is the slug field if the model has one
    // alternatively it's the primary key, if the url options are not passed in.
    if (!isset($options['url'])) {
      if ($model->$habtmAlias->hasField('slug')) {
        $options['url']['slug'] = 'slug';
      } else {
        $options['url'][$model->$habtmAlias->primaryKey] = $model->$habtmAlias->primaryKey;
      }
    }

    $settings = $this->settings[$model->alias][$habtmAlias];

    // Set up the default query options with minimum required fields, plus the
    // ones required to populate the url key in the results returned.
    $defaults = array(
      'fields' => array_merge(array(
        'id',
        'parent_id',
        $model->$habtmAlias->displayField, // The display field of the habtm model
        $settings['underCounterCache'] // The Under Counter Cache field
      ), array_keys($options['url'])), // Add in the fields required for the url
      'conditions' => array(
        $settings['underCounterCache'] . ' >' => 0 // Only return items that have content assigned to or under them
      )
    );

    $query = Set::merge($defaults, $options);

    // Used find('threaded') to get the data out in a nested hierarchy.
    $items = $model->$habtmAlias->find('threaded', $query);

    // Finally we have to format the datastructure to be suitable for rendering
    // as a nested list.
    list($items) = $this->_formatMenuWithUnderCounts($model, $habtmAlias, $items, $options);

    return $items;

  }

  /**
   * A recursive function that processes the results from a find('threaded')
   * call into a format suitable for rendering as a nest list. It combines the
   * displayField with the under counter cache field field into the text key,
   * handles setting the selected/parent-selected keys, generates the url key
   * according to the url element in the options parameter.
   *
   * Called by getMenuWithUnderCounts(), see that method documentation for an
   * example of what this returns.
   *
   * @param AppModel $model
   * @param string $habtmAlias E.g. Category
   * @param array $items Nested array of items such as those returned by
   *        find('threaded')
   * @param array $options See $options for getMenuWithUnderCounts().
   * @return array
   */
  protected function _formatMenuWithUnderCounts(&$model, $habtmAlias, $items, $options) {

    $settings = $this->settings[$model->alias][$habtmAlias];

    // Initalise this to false, if an item is identified as the selected item,
    // it is set to true below and then passed back up the levels of recursion
    // so that parent-selected keys can be set.
    $parentSelected = false;

    foreach ($items as $k => $item) {

      // Set the text key with displayField and under counter cache
      $items[$k] = array(
        'text' => $item[$model->$habtmAlias->alias][$model->$habtmAlias->displayField] . ' (' . $item[$model->$habtmAlias->alias][$settings['underCounterCache']] . ')',
        'id' => $item[$model->$habtmAlias->alias][$model->$habtmAlias->primaryKey],
      );

      // Set the children and parent-selected keys by calling this method again
      // with the items children passed into the $items parameter.
      list ($items[$k]['children'], $items[$k]['parent-selected']) = $this->_formatMenuWithUnderCounts($model, $habtmAlias, $item['children'], $options);

      // Set the url element according to the url key in the options parameter
      foreach ($options['url'] as $fieldName => $urlParamName) {

        $urlParamValue = $item[$model->$habtmAlias->alias][$fieldName];
        $items[$k]['url'][$urlParamName] = $urlParamValue;

        // Check the data against the selected key in the options parameter and
        // set the selected key to true, and the parent selected variable, ready
        // for passing back up the levels of recursion.
        if (isset($options['selected']) && $fieldName == key($options['selected']) && $urlParamValue == current($options['selected'])) {
          $items[$k]['selected'] = true;
          $parentSelected = true;
        }
      }
    }

    return array($items, $parentSelected);

  }

}

?>
