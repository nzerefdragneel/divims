sabre/uri
=========

sabre/uri is a lightweight library that provides several functions for working
with URIs, staying true to the rules of [RFC3986][2].

Partially inspired by [Node.js URL library][3], and created to solve real
problems in PHP applications. 100% unit tested and many tests are based on
examples from RFC3986.

The library provides the following functions:

1. `resolve` to resolve relative urls.
2. `normalize` to aid in comparing urls.
3. `parse`, which works like PHP's [parse_url][6] with special cases for some Windows-style paths [9].
4. `build` to do the exact opposite of `parse`.
5. `split` to easily get the 'dirname' and 'basename' of a URL without all the
   problems those two functions have.


Build status
------------

| branch       | status | minimum PHP version |
| ------------ | ------ | ------------------- |
| master       | [![Build Status](https://travis-ci.org/sabre-io/uri.svg?branch=master)](https://travis-ci.org/sabre-io/uri) | PHP 7.0 |
| 1.x          | [![Build Status](https://travis-ci.org/sabre-io/uri.svg?branch=1.x)](https://travis-ci.org/sabre-io/uri) | PHP 5.4.7 |


Further reading
---------------

* [Installation][7]
* [Usage][8]


Questions?
----------

Head over to the [sabre/dav mailinglist][4], or you can also just open a ticket
on [GitHub][5].


Made at fruux
-------------

This library is being developed by [fruux](https://fruux.com/). Drop us a line for commercial services or enterprise support.

[1]: http://sabre.io/uri/
[2]: https://tools.ietf.org/html/rfc3986/
[3]: http://nodejs.org/api/url.html
[4]: http://groups.google.com/group/sabredav-discuss
[5]: https://github.com/fruux/sabre-uri/issues/
[6]: http://php.net/manual/en/function.parse-url.php
[7]: http://sabre.io/uri/install/
[8]: http://sabre.io/uri/usage/
[9]: https://github.com/sabre-io/uri/pull/71
