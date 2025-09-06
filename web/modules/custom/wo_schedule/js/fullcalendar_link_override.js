(function ($, Drupal) {
    console.log('JS Loaded');
    var checkEvents = setInterval(function () {
      var $events = $('.fc-event');
      if ($events.length) {
        clearInterval(checkEvents);
        console.log('Events Found:', $events.length);
        $events.off('click');
        $events.on('click', function (e) {
          e.preventDefault();
          var url = $(this).attr('href');
          if (url) {
            window.open(url, '_blank');
            console.log('Opened:', url);
          }
        });
      }
    }, 500);
  })(jQuery, Drupal);