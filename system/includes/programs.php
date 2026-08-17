<?php
// Bago City College — Official Program List
$BCC_PROGRAMS = [
    ['code' => 'IS',                 'desc' => 'Information Systems'],
    ['code' => 'CRIM',               'desc' => 'Criminology'],
    ['code' => 'ARTS',               'desc' => 'Arts'],
    ['code' => 'EDUCATION',          'desc' => 'Education'],
    ['code' => 'AB ENGLISH',         'desc' => 'Bachelor of Arts in English Language'],
    ['code' => 'AB HISTORY',         'desc' => 'Bachelor of Arts in History'],
    ['code' => 'BEED',               'desc' => 'Bachelor of Elementary Education'],
    ['code' => 'BPED',               'desc' => 'Bachelor of Physical Education'],
    ['code' => 'BSED-FILIPINO',      'desc' => 'Bachelor of Secondary Education'],
    ['code' => 'BSED-MATHEMATICS',   'desc' => 'Bachelor of Secondary Education'],
    ['code' => 'BSED-SOCIAL STUDIES','desc' => 'Bachelor of Secondary Education'],
    ['code' => 'BSOA',               'desc' => 'Bachelor of Science in Office Administration'],
];

// Course codes — subject codes used when creating a class
// Teachers can also type a custom code; this list provides quick suggestions
$BCC_COURSE_CODES = [
    'AB ENGLISH', 'AB HISTORY',
    'BEED', 'BPED',
    'BSED-FILIPINO', 'BSED-MATHEMATICS', 'BSED-SOCIAL STUDIES',
    'BSOA', 'CRIM', 'IS',
    'GE 1', 'GE 2', 'GE 3', 'GE 4', 'GE 5', 'GE 6', 'GE 7', 'GE 8',
    'MATH 1', 'MATH 2', 'MATH 3',
    'ENG 1', 'ENG 2', 'ENG 3',
    'SCI 1', 'SCI 2',
    'PE 1', 'PE 2', 'PE 3', 'PE 4',
    'NSTP 1', 'NSTP 2',
    'IT 1', 'IT 2', 'IT 3', 'IT 4',
    'CS 1', 'CS 2', 'CS 3',
    'HIST 1', 'HIST 2',
    'FIL 1', 'FIL 2',
    'SOC SCI 1', 'SOC SCI 2',
    'RIZAL', 'ETHICS', 'LOGIC', 'STAT 1',
];
sort($BCC_COURSE_CODES);
?>
