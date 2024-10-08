<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

/**
 * The CiviCRM duplicate discovery engine is based on an
 * algorithm designed by David Strauss <david@fourkitchens.com>.
 */
class CRM_Dedupe_BAO_DedupeRuleGroup extends CRM_Dedupe_DAO_DedupeRuleGroup {

  /**
   * @var array
   *
   * Ids of the contacts to limit the SQL queries (whole-database queries otherwise)
   *
   * @internal
   */
  public $contactIds = [];

  /**
   * Set the contact IDs to restrict the dedupe to.
   *
   * @param array $contactIds
   */
  public function setContactIds($contactIds) {
    CRM_Core_Error::deprecatedWarning('unused');
    $this->contactIds = $contactIds;
  }

  /**
   * Params to dedupe against (queries against the whole contact set otherwise)
   * @var array
   */
  public $params = [];

  /**
   * If there are no rules in rule group.
   *
   * @var bool
   *
   * @deprecated this was introduced in https://github.com/civicrm/civicrm-svn/commit/15136b07013b3477d601ebe5f7aa4f99f801beda
   * as an awkward way to avoid fatalling on an invalid rule set with no rules.
   *
   * Passing around a property is a bad way to do that check & we will work to remove.
   */
  public $noRules = FALSE;

  protected $temporaryTables = [];

  /**
   * Return a structure holding the supported tables, fields and their titles
   *
   * @param string $requestedType
   *   The requested contact type.
   *
   * @return array
   *   a table-keyed array of field-keyed arrays holding supported fields' titles
   */
  public static function supportedFields($requestedType): array {
    if (!isset(Civi::$statics[__CLASS__]['supportedFields'])) {
      // this is needed, as we're piggy-backing importableFields() below
      $replacements = [
        'civicrm_country.name' => 'civicrm_address.country_id',
        'civicrm_county.name' => 'civicrm_address.county_id',
        'civicrm_state_province.name' => 'civicrm_address.state_province_id',
        'civicrm_phone.phone' => 'civicrm_phone.phone_numeric',
      ];
      // the table names we support in dedupe rules - a filter for importableFields()
      $supportedTables = [
        'civicrm_address',
        'civicrm_contact',
        'civicrm_email',
        'civicrm_im',
        'civicrm_note',
        'civicrm_openid',
        'civicrm_phone',
        'civicrm_website',
      ];

      foreach (CRM_Contact_BAO_ContactType::basicTypes() as $ctype) {
        // take the table.field pairs and their titles from importableFields() if the table is supported
        foreach (self::importableFields($ctype) as $iField) {
          if (isset($iField['where'])) {
            $where = $iField['where'];
            if (isset($replacements[$where])) {
              $where = $replacements[$where];
            }
            [$table, $field] = explode('.', $where);
            if (!in_array($table, $supportedTables)) {
              continue;
            }
            $fields[$ctype][$table][$field] = $iField['title'];
          }
        }
        // Note that most of the fields available come from 'importable fields' -
        // I thought about making this field 'importable' but it felt like there might be unknown consequences
        // so I opted for just adding it in & securing it with a unit test.
        /// Example usage of sort_name - It is possible to alter sort name via hook so 2 organization names might differ as in
        // Justice League vs The Justice League but these could have the same sort_name if 'the the'
        // exension is installed (https://github.com/eileenmcnaughton/org.wikimedia.thethe)
        $fields[$ctype]['civicrm_contact']['sort_name'] = ts('Sort Name');

        $customGroups = CRM_Core_BAO_CustomGroup::getAll([
          'extends' => $ctype,
          'is_active' => TRUE,
        ], CRM_Core_Permission::EDIT);
        // add all custom data fields including those only for sub_types.
        foreach ($customGroups as $cg) {
          foreach ($cg['fields'] as $cf) {
            $fields[$ctype][$cg['table_name']][$cf['column_name']] = $cg['title'] . ' : ' . $cf['label'];
          }
        }
      }
      //Does this have to run outside of cache?
      CRM_Utils_Hook::dupeQuery(NULL, 'supportedFields', $fields);
      Civi::$statics[__CLASS__]['supportedFields'] = $fields;
    }

    return Civi::$statics[__CLASS__]['supportedFields'][$requestedType] ?? [];

  }

  /**
   * Combine all the importable fields from the lower levels object.
   *
   * @deprecated - copy of importableFields to unravel.
   *
   * The ordering is important, since currently we do not have a weight
   * scheme. Adding weight is super important
   *
   * @param int|string $contactType contact Type
   *
   * @return array
   *   array of importable Fields
   */
  private static function importableFields($contactType): array {

    $fields = CRM_Contact_DAO_Contact::import();

    $locationFields = array_merge(CRM_Core_DAO_Address::import(),
      CRM_Core_DAO_Phone::import(),
      CRM_Core_DAO_Email::import(),
      CRM_Core_DAO_IM::import(TRUE),
      CRM_Core_DAO_OpenID::import()
    );

    $locationFields = array_merge($locationFields,
      CRM_Core_BAO_CustomField::getFieldsForImport('Address',
        FALSE,
        FALSE,
        FALSE,
        FALSE
      )
    );

    foreach ($locationFields as $key => $field) {
      $locationFields[$key]['hasLocationType'] = TRUE;
    }

    $fields = array_merge($fields, $locationFields);

    $fields = array_merge($fields, CRM_Contact_DAO_Contact::import());
    $fields = array_merge($fields, CRM_Core_DAO_Note::import());

    //website fields
    $fields = array_merge($fields, CRM_Core_DAO_Website::import());
    $fields['url']['hasWebsiteType'] = TRUE;

    $fields = array_merge($fields,
      CRM_Core_BAO_CustomField::getFieldsForImport($contactType,
        FALSE,
        TRUE,
        FALSE,
        FALSE,
        FALSE
      )
    );
    // Unset the fields which are not related to their contact type.
    foreach (CRM_Contact_DAO_Contact::import() as $name => $value) {
      if (!empty($value['contactType']) && $value['contactType'] !== $contactType) {
        unset($fields[$name]);
      }
    }

    //Sorting fields in alphabetical order(CRM-1507)
    return CRM_Utils_Array::crmArraySortByField($fields, 'title');
  }

  /**
   * Return the SQL query for dropping the temporary table.
   */
  public function tableDropQuery() {
    return 'DROP TEMPORARY TABLE IF EXISTS dedupe';
  }

  /**
   * Return a set of SQL queries whose cummulative weights will mark matched
   * records for the RuleGroup::threasholdQuery() to retrieve.
   *
   * @param array|null $params
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  private function tableQuery($params) {
    $contactType = $this->contact_type;

    // Reserved Rule Groups can optionally get special treatment by
    // implementing an optimization class and returning a query array.
    if ($this->isUseReservedQuery()) {
      $command = empty($params) ? 'internal' : 'record';
      $queries = call_user_func(["CRM_Dedupe_BAO_QueryBuilder_{$this->name}", $command], $this);
    }
    else {
      // All other rule groups have queries generated by the member dedupe
      // rules defined in the administrative interface.
      $optimizer = new CRM_Dedupe_FinderQueryOptimizer($this->id);
      $rules = $optimizer->getRules();

      // Generate a SQL query for each rule in the rule group that is
      // tailored to respect the param and contactId options provided.
      $queries = [];
      foreach ($rules as $rule) {
        $key = "{$rule['rule_table']}.{$rule['rule_field']}.{$rule['rule_weight']}";
        // if params is present and doesn't have an entry for a field, don't construct the clause.
        if (!$params || (array_key_exists($rule['rule_table'], $params) && array_key_exists($rule['rule_field'], $params[$rule['rule_table']]))) {
          $queries[$key] = self::sql($params, $this->contactIds, $rule, $contactType);
        }
      }
    }

    return $queries;
  }

  /**
   * Return the SQL query for the given rule - either for finding matching
   * pairs of contacts, or for matching against the $params variable (if set).
   *
   * @param array|null $params
   *   Params to dedupe against (queries against the whole contact set otherwise)
   * @param array $contactIDs
   *   Ids of the contacts to limit the SQL queries (whole-database queries otherwise)
   * @param array $rule
   * @param string $contactType
   *
   * @return string
   *   SQL query performing the search
   *   or NULL if params is present and doesn't have and for a field.
   *
   * @throws \CRM_Core_Exception
   * @internal do not call from outside tested core code. No universe uses Feb 2024.
   *
   */
  private static function sql($params, $contactIDs, array $rule, string $contactType): ?string {

    $filter = self::getRuleTableFilter($rule['rule_table'], $contactType);
    $contactIDFieldName = self::getContactIDFieldName($rule['rule_table']);

    // build FROM (and WHERE, if it's a parametrised search)
    // based on whether the rule is about substrings or not
    if ($params) {
      $select = "t1.$contactIDFieldName id1, {$rule['rule_weight']} weight";
      $subSelect = 'id1, weight';
      $where = $filter ? ['t1.' . $filter] : [];
      $from = "{$rule['rule_table']} t1";
      $str = 'NULL';
      if (isset($params[$rule['rule_table']][$rule['rule_field']])) {
        $str = trim(CRM_Utils_Type::escape($params[$rule['rule_table']][$rule['rule_field']], 'String'));
      }
      if ($rule['rule_length']) {
        $where[] = "SUBSTR(t1.{$rule['rule_field']}, 1, {$rule['rule_length']}) = SUBSTR('$str', 1, {$rule['rule_length']})";
        $where[] = "t1.{$rule['rule_field']} IS NOT NULL";
      }
      else {
        $where[] = "t1.{$rule['rule_field']} = '$str'";
      }
    }
    else {
      $select = "t1.$contactIDFieldName id1, t2.$contactIDFieldName id2, {$rule['rule_weight']} weight";
      $subSelect = 'id1, id2, weight';
      $where = $filter ? [
        't1.' . $filter,
        't2.' . $filter,
      ] : [];
      $where[] = "t1.$contactIDFieldName < t2.$contactIDFieldName";
      $from = "{$rule['rule_table']} t1 INNER JOIN {$rule['rule_table']} t2 ON (" . self::getRuleFieldFilter($rule) . ")";
    }

    $query = "SELECT $select FROM $from WHERE " . implode(' AND ', $where);
    if ($contactIDs) {
      $cids = [];
      foreach ($contactIDs as $cid) {
        $cids[] = CRM_Utils_Type::escape($cid, 'Integer');
      }
      $query .= " AND t1.$contactIDFieldName IN (" . implode(',', $cids) . ")
      UNION $query AND  t2.$contactIDFieldName IN (" . implode(',', $cids) . ")";

      // The `weight` is ambiguous in the context of the union; put the whole
      // thing in a subquery.
      $query = "SELECT $subSelect FROM ($query) subunion";
    }

    return $query;
  }

  /**
   * Get the name of the field in the table that refers to the Contact ID.
   *
   * e.g in civicrm_contact this is 'id' whereas in civicrm_address this is
   * contact_id and in a custom field table it might be entity_id.
   *
   * @param string $tableName
   *
   * @return string
   *   Usually id, contact_id or entity_id.
   * @throws \CRM_Core_Exception
   */
  private static function getContactIDFieldName(string $tableName): string {
    if ($tableName === 'civicrm_contact') {
      return 'id';
    }
    if (isset(CRM_Core_DAO::getDynamicReferencesToTable('civicrm_contact')[$tableName][0])) {
      return CRM_Core_DAO::getDynamicReferencesToTable('civicrm_contact')[$tableName][0];
    }
    if (isset(\CRM_Core_DAO::getReferencesToContactTable()[$tableName][0])) {
      return \CRM_Core_DAO::getReferencesToContactTable()[$tableName][0];
    }
    throw new CRM_Core_Exception('invalid field');
  }

  /**
   * Get any where filter that restricts the specific table.
   *
   * Generally this is along the lines of entity_table = civicrm_contact
   * although for the contact table it could be the id restriction.
   *
   * @param string $tableName
   * @param string $contactType
   *
   * @return string
   */
  private static function getRuleTableFilter(string $tableName, string $contactType): string {
    if ($tableName === 'civicrm_contact') {
      return "contact_type = '{$contactType}'";
    }
    $dynamicReferences = CRM_Core_DAO::getDynamicReferencesToTable('civicrm_contact')[$tableName] ?? NULL;
    if (!$dynamicReferences) {
      return '';
    }
    if (!empty(CRM_Core_DAO::getDynamicReferencesToTable('civicrm_contact')[$tableName])) {
      return $dynamicReferences[1] . "= 'civicrm_contact'";
    }
    return '';
  }

  /**
   * @param array $rule
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  private static function getRuleFieldFilter(array $rule): string {
    if ($rule['rule_length']) {
      $on = ["SUBSTR(t1.{$rule['rule_field']}, 1, {$rule['rule_length']}) = SUBSTR(t2.{$rule['rule_field']}, 1, {$rule['rule_length']})"];
      return "(" . implode(' AND ', $on) . ")";
    }
    $innerJoinClauses = [
      "t1.{$rule['rule_field']} IS NOT NULL",
      "t2.{$rule['rule_field']} IS NOT NULL",
      "t1.{$rule['rule_field']} = t2.{$rule['rule_field']}",
    ];

    if (in_array(CRM_Dedupe_BAO_DedupeRule::getFieldType($rule['rule_field'], $rule['rule_table']), CRM_Utils_Type::getTextTypes(), TRUE)) {
      $innerJoinClauses[] = "t1.{$rule['rule_field']} <> ''";
      $innerJoinClauses[] = "t2.{$rule['rule_field']} <> ''";
    }
    return "(" . implode(' AND ', $innerJoinClauses) . ")";
  }

  /**
   * Fill the dedupe finder table.
   *
   * @internal do not access from outside core.
   *
   * @param int $id
   * @param array $contactIDs
   * @param array $params
   *
   * @return void
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function fillTable(int $id, array $contactIDs, array $params): void {
    $this->contactIds = $contactIDs;
    $this->params = $params;
    $this->id = $id;
    // make sure we've got a fetched dbrecord, not sure if this is enforced
    $this->find(TRUE);

    // get the list of queries handy
    $tableQueries = $this->tableQuery($params);
    // if there are no rules in this rule group
    // add an empty query fulfilling the pattern
    if (!$tableQueries) {
      // Yeah not too sure why but ....,
      $this->noRules = TRUE;
    }

    if ($params && !empty($tableQueries)) {
      $this->temporaryTables['dedupe'] = CRM_Utils_SQL_TempTable::build()
        ->setCategory('dedupe')
        ->createWithColumns("id1 int, weight int, UNIQUE UI_id1 (id1)")->getName();
      $dedupeCopyTemporaryTableObject = CRM_Utils_SQL_TempTable::build()
        ->setCategory('dedupe');
      $this->temporaryTables['dedupe_copy'] = $dedupeCopyTemporaryTableObject->getName();
      $insertClause = "INSERT INTO {$this->temporaryTables['dedupe']}  (id1, weight)";
      $groupByClause = "GROUP BY id1, weight";
      $dupeCopyJoin = " JOIN {$this->temporaryTables['dedupe_copy']} ON {$this->temporaryTables['dedupe_copy']}.id1 = t1.column WHERE ";
    }
    else {
      $this->temporaryTables['dedupe'] = CRM_Utils_SQL_TempTable::build()
        ->setCategory('dedupe')
        ->createWithColumns("id1 int, id2 int, weight int, UNIQUE UI_id1_id2 (id1, id2)")->getName();
      $dedupeCopyTemporaryTableObject = CRM_Utils_SQL_TempTable::build()
        ->setCategory('dedupe');
      $this->temporaryTables['dedupe_copy'] = $dedupeCopyTemporaryTableObject->getName();
      $insertClause = "INSERT INTO {$this->temporaryTables['dedupe']}  (id1, id2, weight)";
      $groupByClause = "GROUP BY id1, id2, weight";
      $dupeCopyJoin = " JOIN {$this->temporaryTables['dedupe_copy']} ON {$this->temporaryTables['dedupe_copy']}.id1 = t1.column AND {$this->temporaryTables['dedupe_copy']}.id2 = t2.column WHERE ";
    }
    $patternColumn = '/t1.(\w+)/';
    $exclWeightSum = [];

    CRM_Utils_Hook::dupeQuery($this, 'table', $tableQueries);

    while (!empty($tableQueries)) {
      [$isInclusive, $isDie] = self::isQuerySetInclusive($tableQueries, $this->threshold, $exclWeightSum);

      if ($isInclusive) {
        // order queries by table count
        self::orderByTableCount($tableQueries);

        $weightSum = array_sum($exclWeightSum);
        $searchWithinDupes = !empty($exclWeightSum) ? 1 : 0;

        while (!empty($tableQueries)) {
          // extract the next query ( and weight ) to be executed
          $fieldWeight = array_keys($tableQueries);
          $fieldWeight = $fieldWeight[0];
          $query = array_shift($tableQueries);

          if ($searchWithinDupes) {
            // drop dedupe_copy table just in case if its already there.
            $dedupeCopyTemporaryTableObject->drop();
            // get prepared to search within already found dupes if $searchWithinDupes flag is set
            $dedupeCopyTemporaryTableObject->createWithQuery("SELECT * FROM {$this->temporaryTables['dedupe']} WHERE weight >= {$weightSum}");

            preg_match($patternColumn, $query, $matches);
            $query = str_replace(' WHERE ', str_replace('column', $matches[1], $dupeCopyJoin), $query);

            // CRM-19612: If there's a union, there will be two WHEREs, and you
            // can't use the temp table twice.
            if (preg_match('/' . $this->temporaryTables['dedupe_copy'] . '[\S\s]*(union)[\S\s]*' . $this->temporaryTables['dedupe_copy'] . '/i', $query, $matches, PREG_OFFSET_CAPTURE)) {
              // Make a second temp table:
              $this->temporaryTables['dedupe_copy_2'] = CRM_Utils_SQL_TempTable::build()
                ->setCategory('dedupe')
                ->createWithQuery("SELECT * FROM {$this->temporaryTables['dedupe']} WHERE weight >= {$weightSum}")
                ->getName();
              // After the union, use that new temp table:
              $part1 = substr($query, 0, $matches[1][1]);
              $query = $part1 . str_replace($this->temporaryTables['dedupe_copy'], $this->temporaryTables['dedupe_copy_2'], substr($query, $matches[1][1]));
            }
          }
          $searchWithinDupes = 1;

          // construct and execute the intermediate query
          $query = "{$insertClause} {$query} {$groupByClause} ON DUPLICATE KEY UPDATE weight = weight + VALUES(weight)";
          $dao = CRM_Core_DAO::executeQuery($query);

          // FIXME: we need to be more accurate with affected rows, especially for insert vs duplicate insert.
          // And that will help optimize further.
          $affectedRows = $dao->affectedRows();

          // In an inclusive situation, failure of any query means no further processing -
          if ($affectedRows == 0) {
            // reset to make sure no further execution is done.
            $tableQueries = [];
            break;
          }
          $weightSum = substr($fieldWeight, strrpos($fieldWeight, '.') + 1) + $weightSum;
        }
        // An exclusive situation -
      }
      elseif (!$isDie) {
        // since queries are already sorted by weights, we can continue as is
        $fieldWeight = array_keys($tableQueries);
        $fieldWeight = $fieldWeight[0];
        $query = array_shift($tableQueries);
        $query = "{$insertClause} {$query} {$groupByClause} ON DUPLICATE KEY UPDATE weight = weight + VALUES(weight)";
        $dao = CRM_Core_DAO::executeQuery($query);
        if ($dao->affectedRows() >= 1) {
          $exclWeightSum[] = substr($fieldWeight, strrpos($fieldWeight, '.') + 1);
        }
      }
      else {
        // its a die situation
        break;
      }
    }
  }

  /**
   * Function to determine if a given query set contains inclusive or exclusive set of weights.
   * The function assumes that the query set is already ordered by weight in desc order.
   * @param $tableQueries
   * @param $threshold
   * @param array $exclWeightSum
   *
   * @return array
   */
  public static function isQuerySetInclusive($tableQueries, $threshold, $exclWeightSum = []) {
    $input = [];
    foreach ($tableQueries as $key => $query) {
      $input[] = substr($key, strrpos($key, '.') + 1);
    }

    if (!empty($exclWeightSum)) {
      $input = array_merge($input, $exclWeightSum);
      rsort($input);
    }

    if (count($input) == 1) {
      return [FALSE, $input[0] < $threshold];
    }

    $totalCombinations = 0;
    for ($i = 0; $i < count($input); $i++) {
      $combination = [$input[$i]];
      if (array_sum($combination) >= $threshold) {
        $totalCombinations++;
        continue;
      }
      for ($j = $i + 1; $j < count($input); $j++) {
        $combination[] = $input[$j];
        if (array_sum($combination) >= $threshold) {
          $totalCombinations++;
        }
      }
    }
    return [$totalCombinations == 1, $totalCombinations <= 0];
  }

  /**
   * Sort queries by number of records for the table associated with them.
   *
   * @param array $tableQueries
   */
  public static function orderByTableCount(array &$tableQueries): void {
    uksort($tableQueries, [__CLASS__, 'isTableBigger']);
  }

  /**
   * Is the table extracted from the first string larger than the second string.
   *
   * @param string $a
   *   e.g civicrm_contact.first_name
   * @param string $b
   *   e.g civicrm_address.street_address
   *
   * @return int
   */
  private static function isTableBigger(string $a, string $b): int {
    $tableA = explode('.', $a)[0];
    $tableB = explode('.', $b)[0];
    if ($tableA === $tableB) {
      return 0;
    }
    return CRM_Core_BAO_SchemaHandler::getRowCountForTable($tableA) <=> CRM_Core_BAO_SchemaHandler::getRowCountForTable($tableB);
  }

  /**
   * Return the SQL query for getting only the interesting results out of the dedupe table.
   *
   * @$checkPermission boolean $params a flag to indicate if permission should be considered.
   * default is to always check permissioning but public pages for example might not want
   * permission to be checked for anonymous users. Refer CRM-6211. We might be beaking
   * Multi-Site dedupe for public pages.
   *
   * @param bool $checkPermission
   *
   * @return string
   */
  public function thresholdQuery($checkPermission = TRUE) {
    $this->_aclFrom = '';
    $aclWhere = '';

    if ($this->params && !$this->noRules) {
      if ($checkPermission) {
        [$this->_aclFrom, $aclWhere] = CRM_Contact_BAO_Contact_Permission::cacheClause('civicrm_contact');
        $aclWhere = $aclWhere ? "AND {$aclWhere}" : '';
      }
      $query = "SELECT {$this->temporaryTables['dedupe']}.id1 as id
                FROM {$this->temporaryTables['dedupe']} JOIN civicrm_contact ON {$this->temporaryTables['dedupe']}.id1 = civicrm_contact.id {$this->_aclFrom}
                WHERE contact_type = '{$this->contact_type}' AND is_deleted = 0 $aclWhere
                AND weight >= {$this->threshold}";
    }
    else {
      $aclWhere = '';
      if ($checkPermission) {
        [$this->_aclFrom, $aclWhere] = CRM_Contact_BAO_Contact_Permission::cacheClause(['c1', 'c2']);
        $aclWhere = $aclWhere ? "AND {$aclWhere}" : '';
      }
      $query = "SELECT IF({$this->temporaryTables['dedupe']}.id1 < {$this->temporaryTables['dedupe']}.id2, {$this->temporaryTables['dedupe']}.id1, {$this->temporaryTables['dedupe']}.id2) as id1,
                IF({$this->temporaryTables['dedupe']}.id1 < {$this->temporaryTables['dedupe']}.id2, {$this->temporaryTables['dedupe']}.id2, {$this->temporaryTables['dedupe']}.id1) as id2, {$this->temporaryTables['dedupe']}.weight
                FROM {$this->temporaryTables['dedupe']} JOIN civicrm_contact c1 ON {$this->temporaryTables['dedupe']}.id1 = c1.id
                            JOIN civicrm_contact c2 ON {$this->temporaryTables['dedupe']}.id2 = c2.id {$this->_aclFrom}
                       LEFT JOIN civicrm_dedupe_exception exc ON {$this->temporaryTables['dedupe']}.id1 = exc.contact_id1 AND {$this->temporaryTables['dedupe']}.id2 = exc.contact_id2
                WHERE c1.contact_type = '{$this->contact_type}' AND
                      c2.contact_type = '{$this->contact_type}'
                       AND c1.is_deleted = 0 AND c2.is_deleted = 0
                      {$aclWhere}
                      AND weight >= {$this->threshold} AND exc.contact_id1 IS NULL";
    }

    CRM_Utils_Hook::dupeQuery($this, 'threshold', $query);
    return $query;
  }

  /**
   * find fields related to a rule group.
   *
   * @param array $params
   *
   * @return array
   *   (rule field => weight) array and threshold associated to rule group
   */
  public static function dedupeRuleFieldsWeight($params) {
    $rgBao = new CRM_Dedupe_BAO_DedupeRuleGroup();
    $rgBao->contact_type = $params['contact_type'];
    if (!empty($params['id'])) {
      // accept an ID if provided
      $rgBao->id = $params['id'];
    }
    else {
      $rgBao->used = $params['used'];
    }
    $rgBao->find(TRUE);

    $ruleBao = new CRM_Dedupe_BAO_DedupeRule();
    $ruleBao->dedupe_rule_group_id = $rgBao->id;
    $ruleBao->find();
    $ruleFields = [];
    while ($ruleBao->fetch()) {
      $field_name = $ruleBao->rule_field;
      if ($field_name == 'phone_numeric') {
        $field_name = 'phone';
      }
      $ruleFields[$field_name] = $ruleBao->rule_weight;
    }

    return [$ruleFields, $rgBao->threshold];
  }

  /**
   * Get all of the combinations of fields that would work with a rule.
   *
   * @param array $rgFields
   * @param int $threshold
   * @param array $combos
   * @param array $running
   */
  public static function combos($rgFields, $threshold, &$combos, $running = []) {
    foreach ($rgFields as $rgField => $weight) {
      unset($rgFields[$rgField]);
      $diff = $threshold - $weight;
      $runningnow = $running;
      $runningnow[] = $rgField;
      if ($diff > 0) {
        self::combos($rgFields, $diff, $combos, $runningnow);
      }
      else {
        $combos[] = $runningnow;
      }
    }
  }

  /**
   * Get an array of rule group id to rule group name
   * for all th groups for that contactType. If contactType
   * not specified, do it for all
   *
   * @param string $contactType
   *   Individual, Household or Organization.
   *
   *
   * @return array|string[]
   *   id => "nice name" of rule group
   */
  public static function getByType($contactType = NULL): array {
    $dao = new CRM_Dedupe_DAO_DedupeRuleGroup();

    if ($contactType) {
      $dao->contact_type = $contactType;
    }

    $dao->find();
    $result = [];
    while ($dao->fetch()) {
      $title = !empty($dao->title) ? $dao->title : (!empty($dao->name) ? $dao->name : $dao->contact_type);

      $name = "$title - {$dao->used}";
      $result[$dao->id] = $name;
    }
    return $result;
  }

  /**
   * Get the cached contact type for a particular rule group.
   *
   * @param int $rule_group_id
   *
   * @return string
   */
  public static function getContactTypeForRuleGroup($rule_group_id) {
    if (!isset(\Civi::$statics[__CLASS__]) || !isset(\Civi::$statics[__CLASS__]['rule_groups'])) {
      \Civi::$statics[__CLASS__]['rule_groups'] = [];
    }
    if (empty(\Civi::$statics[__CLASS__]['rule_groups'][$rule_group_id])) {
      \Civi::$statics[__CLASS__]['rule_groups'][$rule_group_id]['contact_type'] = CRM_Core_DAO::getFieldValue(
        'CRM_Dedupe_DAO_DedupeRuleGroup',
        $rule_group_id,
        'contact_type'
      );
    }

    return \Civi::$statics[__CLASS__]['rule_groups'][$rule_group_id]['contact_type'];
  }

  /**
   * Is a file based reserved query configured.
   *
   * File based reserved queries were an early idea about how to optimise the dedupe queries.
   *
   * In theory extensions could implement them although there is no evidence any of them have.
   * However, if these are implemented by core or by extensions we should not attempt to optimise
   * the query by (e.g.) combining queries.
   *
   * In practice the queries implemented only return one query anyway
   *
   * @see \CRM_Dedupe_BAO_QueryBuilder_IndividualGeneral
   * @see \CRM_Dedupe_BAO_QueryBuilder_IndividualSupervised
   * @see \CRM_Dedupe_BAO_QueryBuilder_IndividualUnsupervised
   *
   * @return bool
   */
  private function isUseReservedQuery(): bool {
    return $this->is_reserved &&
      CRM_Utils_File::isIncludable("CRM/Dedupe/BAO/QueryBuilder/{$this->name}.php");
  }

}
