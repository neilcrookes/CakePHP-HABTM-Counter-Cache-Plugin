CakePHP HABTM Counter Cache Plugin
==================================

A CakePHP plugin for Counter Cache functionality for HABTM associations

Details
-------

Provides Counter Cache and Under Counter Cache (for hierarchical models)
behavior with scope for HABTM associations e.g. Post habtm Tag,Category where
Tag has a post_count and Category is a hierarchical and has post_count and
under_post_count fields.

Under Counter Cache is a Counter Cache that counts all distinct records
assigned to that node explicitly, or any of it's children. This is useful for
figuring out how many posts are assigned to a category of one of it's
children, which you can use to print out a nested list of categories with the
number of posts next to them.

Usage example:

    class Post extends AppModel {
      var $name = 'Post';
      var $actsAs = array('HabtmCounterCache.HabtmCounterCache');
      var $hasAndBelongsToMany = array('Category', 'Tag');
    }


Requirements
------------

* CakePHP 1.3+ (I think) (Works with CakePHP v2.0)

Installation
------------

    git submodule add git://github.com/neilcrookes/CakePHP-HABTM-Counter-Cache-Plugin.git app/plugins/habtm_counter_cache

or download from http://github.com/neilcrookes/CakePHP-HABTM-Counter-Cache-Plugin

Add the counter cache fields to your database tables, e.g. for a tags table, add an integer field, default 0, called 'post_count'

Attach the behavior to the model whose records you're counting, e.g. your Post model (see configuration below for examples)

Configuration
-------------

Configuration is very flexible, for example:

* Just attach and it will do counter caching for all hatbm associated models that have the counterCache field. E.g.

    var $actsAs = array('HabtmCounterCache.HabtmCounterCache');

* Specify counterCache, counterScope and/or underCounterCache keys in the configuration options when you attach the behavior for these settings to be applied to all habtm associations. E.g.

    var $actsAs = array(
      'HabtmCounterCache.HabtmCounterCache' => array(
        'counterScope' => array('active' => 1)
      ),
    );

* Introduce habtm association specific counterCache and counterScope settings by using the habtm alias as the key E.g.

    var $actsAs = array(
      'HabtmCounterCache.HabtmCounterCache' => array(
        'Tag' => array(
          'counterCache' => 'weight'
        )
      ),
    );

Copyright
---------

Copyright Neil Crookes 2011

License
-------

The MIT License
