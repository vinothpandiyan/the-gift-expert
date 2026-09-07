<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gift Ideas hub destinations
    |--------------------------------------------------------------------------
    |
    | Editorial, high-value destinations for the unfiltered Gift Ideas hub.
    | Missing or inactive slugs are skipped at resolve time. This is not a
    | dump of every active taxonomy row.
    |
    */

    'recipients' => [
        ['taxonomy' => 'relationship', 'slug' => 'husband'],
        ['taxonomy' => 'relationship', 'slug' => 'wife'],
        ['taxonomy' => 'relationship', 'slug' => 'boyfriend'],
        ['taxonomy' => 'relationship', 'slug' => 'girlfriend'],
        ['taxonomy' => 'relationship', 'slug' => 'father'],
        ['taxonomy' => 'relationship', 'slug' => 'mother'],
        ['taxonomy' => 'recipient_type', 'slug' => 'kids'],
        ['taxonomy' => 'relationship', 'slug' => 'friends'],
        ['taxonomy' => 'relationship', 'slug' => 'colleagues'],
    ],

    'occasions' => [
        'birthday',
        'anniversary',
        'wedding',
        'housewarming',
        'diwali',
        'holi',
        'raksha-bandhan',
        'eid',
        'valentines-day',
        'mothers-day',
        'fathers-day',
        'just-because',
    ],

    'interests' => [
        'technology',
        'food',
        'coffee',
        'books',
        'gaming',
        'fitness',
        'self-care-wellness',
        'travel',
        'photography',
        'wfh-desk-setup',
    ],

    'gift_types' => [
        'personalized-gifts',
        'hampers-gift-sets',
        'experience-gifts',
        'gift-cards',
        'return-gifts',
    ],

    'categories' => [
        'home-and-living',
        'fashion-and-accessories',
        'electronics',
        'beauty-and-grooming',
        'food-and-beverages',
        'wellness',
        'books',
        'toys-and-games',
        'stationery-and-office',
        'spiritual-and-pooja',
        'sports-and-outdoors',
    ],

];
