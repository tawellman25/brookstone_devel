


$(document).ready(function(){




  $(window).on('load', function() { 
    $('#loading').fadeOut();  
    $('#page-loader').delay(350).fadeOut('slow'); 
    $('body').delay(350).css({'overflow':'visible'});
  });




  $(function () {
    $(document).scroll(function () {
      var $nav = $(".back-to-top");
      $nav.toggleClass("back-to-top-hide", $(this).scrollTop() < 500);
    });
  });



  $(function () {
    $(document).scroll(function () {
      var $nav = $("#header-1 .navigation-sticky");
      $nav.toggleClass("header-fixed", $(this).scrollTop() > 0);
    });
  });
  $(function () {
    $(document).scroll(function () {
      var $nav = $("#header-2 .navigation-sticky");
      $nav.toggleClass("header-fixed", $(this).scrollTop() > 0);
    });
  });
  $(function () {
    $(document).scroll(function () {
      var $nav = $("#header-3 .navigation-sticky");
      var $height = $("#header-3 .topbar");
      $height.toggleClass("margin-top-0", $(this).scrollTop() > $height.height());
      $nav.toggleClass("header-fixed", $(this).scrollTop() > $height.height());
    });
  });




  $(".search-btn .btn").click(function(){
    $(".search-btn .search-overlay").toggleClass("search-block");
  });



  $(".dropdown-menu a.drop-toggle").on("click", function (e) {
    if (!$(this).next().hasClass("show")) {
      $(this)
        .parents(".dropdown-menu")
        .first()
        .find(".show")
        .removeClass("show");
    }
    var $subMenu = $(this).next(".dropdown-menu");
    $subMenu.toggleClass("show");
    $(this).parent("li").toggleClass("show");
    $(this)
      .parents("li.nav-item.dropdown.show")
      .on("hidden.bs.dropdown", function (e) {
        $(".dropdown-menu .show").removeClass("show");
      });
    return false;
  });




  var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
  var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl)
  })


  $('select').niceSelect();





// Owl Carousel
$(function () {
  $(".style-1-slider").owlCarousel({
    autoplay: true,
    autoplayTimeout: 5000,
    loop: true,
    margin: 0,
    nav: false,
    responsive: {
        0:{
            items:1
        },
        600:{
            items:1
        },
        1000:{
            items:1
        }
    }
  });
  var owl = $(".style-1-slider");
  owl.owlCarousel();
  $(".slider-style-1 .arrows .next").click(function () {
    owl.trigger("next.owl.carousel");
  });
  $(".slider-style-1 .arrows .prev").click(function () {
    owl.trigger("prev.owl.carousel");
  });
  $(".style-1-slider .owl-dots").addClass("owl-dots-1");
  $(".style-1-slider .owl-dot").html('<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 36 36"><g id="Group_483" data-name="Group 483" transform="translate(-603.32 -8225.32)"><g id="Stroke" data-name="Rectangle 348" transform="translate(603.32 8225.32)" fill="none" stroke="#222025" stroke-width="1"><rect width="36" height="36" rx="18" stroke="none"/><rect x="0.5" y="0.5" width="35" height="35" rx="17.5" fill="none"/></g><rect id="innerDot" data-name="Rectangle 347" width="14" height="14" rx="7" transform="translate(614.32 8236.32)" fill="#222025"/></g></svg>');
});
$(function () {
  $(".style-2-slider").owlCarousel({
    autoplay: true,
    autoplayTimeout: 5000,
    loop: true,
    margin: 0,
    nav: false,
    responsive: {
        0:{
            items:1
        },
        600:{
            items:1
        },
        1000:{
            items:1
        }
    }
  });
  $(".style-2-slider .owl-dots").addClass("owl-dots-1 white");
  $(".style-2-slider .owl-dot").html('<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 36 36"><g id="Group_483" data-name="Group 483" transform="translate(-603.32 -8225.32)"><g id="Stroke" data-name="Rectangle 348" transform="translate(603.32 8225.32)" fill="none" stroke="#222025" stroke-width="1"><rect width="36" height="36" rx="18" stroke="none"/><rect x="0.5" y="0.5" width="35" height="35" rx="17.5" fill="none"/></g><rect id="innerDot" data-name="Rectangle 347" width="14" height="14" rx="7" transform="translate(614.32 8236.32)" fill="#222025"/></g></svg>');
});
$(function () {
  $(".style-3-slider").owlCarousel({
    autoplay: true,
    autoplayTimeout: 5000,
    loop: true,
    margin: 30,
    nav: false,
    responsive: {
        0:{
            items:1
        },
        600:{
            items:2
        },
        1000:{
            items:3
        }
    }
  });
  $(".style-3-slider .owl-dots").addClass("owl-dots-1");
  $(".style-3-slider .owl-dot").html('<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 36 36"><g id="Group_483" data-name="Group 483" transform="translate(-603.32 -8225.32)"><g id="Stroke" data-name="Rectangle 348" transform="translate(603.32 8225.32)" fill="none" stroke="#222025" stroke-width="1"><rect width="36" height="36" rx="18" stroke="none"/><rect x="0.5" y="0.5" width="35" height="35" rx="17.5" fill="none"/></g><rect id="innerDot" data-name="Rectangle 347" width="14" height="14" rx="7" transform="translate(614.32 8236.32)" fill="#222025"/></g></svg>');
});
$(function () {
  $(".style-4-slider").owlCarousel({
    autoplay: true,
    autoplayTimeout: 5000,
    loop: true,
    margin: 10,
    nav: false,
    dots: false,
    responsive: {
        0:{
            items:1
        },
        600:{
            items:2
        },
        1000:{
            items:4
        }
    }
  });
  var owl = $(".style-4-slider");
  owl.owlCarousel();
  $(".slider-style-4 .arrows .next").click(function () {
    owl.trigger("next.owl.carousel");
  });
  $(".slider-style-4 .arrows .prev").click(function () {
    owl.trigger("prev.owl.carousel");
  });
});
$(function () {
  $(".style-5-slider").owlCarousel({
    autoplay: true,
    autoplayTimeout: 5000,
    loop: true,
    margin: 30,
    nav: false,
    responsive: {
        0:{
            items:1
        },
        600:{
            items:2
        },
        1000:{
            items:3
        }
    }
  });
  $(".style-5-slider .owl-dots").addClass("owl-dots-1");
  $(".style-5-slider .owl-dot").html('<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 36 36"><g id="Group_483" data-name="Group 483" transform="translate(-603.32 -8225.32)"><g id="Stroke" data-name="Rectangle 348" transform="translate(603.32 8225.32)" fill="none" stroke="#222025" stroke-width="1"><rect width="36" height="36" rx="18" stroke="none"/><rect x="0.5" y="0.5" width="35" height="35" rx="17.5" fill="none"/></g><rect id="innerDot" data-name="Rectangle 347" width="14" height="14" rx="7" transform="translate(614.32 8236.32)" fill="#222025"/></g></svg>');
});
$(function () {
  $(".style-6-slider").owlCarousel({
    autoplay: true,
    autoplayTimeout: 2000,
    loop: true,
    margin: 90,
    nav: false,
    dots: false,
    responsive: {
        0:{
            items:1
        },
        600:{
            items:3
        },
        1000:{
            items:4
        },
        1025:{
            items:5
        }
    }
  });
});
$(function () {
  $(".home-1-slider").owlCarousel({
    autoplay: true,
    autoplayTimeout: 5000,
    loop: true,
    margin: 100,
    dots: true,
    nav: false,
    responsive: {
        0:{
            items:1
        },
        600:{
            items:1
        },
        1000:{
            items:1
        }
    }
  });
  $(".home-1-slider .owl-dots").addClass("owl-dots-1");
  $(".home-1-slider .owl-dot").html('<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 36 36"><g id="Group_483" data-name="Group 483" transform="translate(-603.32 -8225.32)"><g id="Stroke" data-name="Rectangle 348" transform="translate(603.32 8225.32)" fill="none" stroke="#222025" stroke-width="1"><rect width="36" height="36" rx="18" stroke="none"/><rect x="0.5" y="0.5" width="35" height="35" rx="17.5" fill="none"/></g><rect id="innerDot" data-name="Rectangle 347" width="14" height="14" rx="7" transform="translate(614.32 8236.32)" fill="#222025"/></g></svg>');
});
$(function () {
  $(".home-2-slider").owlCarousel({
    autoplay: true,
    autoplayTimeout: 5000,
    loop: true,
    margin: 0,
    dots: true,
    nav: false,
    responsive: {
        0:{
            items:1
        },
        600:{
            items:1
        },
        1000:{
            items:1
        }
    }
  });
  $(".home-2-slider .owl-dots").addClass("owl-dots-1 white");
  $(".home-2-slider .owl-dot").html('<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 36 36"><g id="Group_483" data-name="Group 483" transform="translate(-603.32 -8225.32)"><g id="Stroke" data-name="Rectangle 348" transform="translate(603.32 8225.32)" fill="none" stroke="#222025" stroke-width="1"><rect width="36" height="36" rx="18" stroke="none"/><rect x="0.5" y="0.5" width="35" height="35" rx="17.5" fill="none"/></g><rect id="innerDot" data-name="Rectangle 347" width="14" height="14" rx="7" transform="translate(614.32 8236.32)" fill="#222025"/></g></svg>');
  var owl = $(".home-2-slider");
  owl.owlCarousel();
  $(".home-slider-2 .arrows .next").click(function () {
    owl.trigger("next.owl.carousel");
  });
  $(".home-slider-2 .arrows .prev").click(function () {
    owl.trigger("prev.owl.carousel");
  });
});
$(function () {
  $(".home-3-slider").owlCarousel({
    autoplay: true,
    autoplayTimeout: 5000,
    loop: true,
    margin: 0,
    dots: true,
    nav: false,
    responsive: {
        0:{
            items:1
        },
        600:{
            items:1
        },
        1000:{
            items:1
        }
    }
  });
  $(".home-3-slider .owl-dots").addClass("owl-dots-1 white");
  $(".home-3-slider .owl-dot").html('<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 36 36"><g id="Group_483" data-name="Group 483" transform="translate(-603.32 -8225.32)"><g id="Stroke" data-name="Rectangle 348" transform="translate(603.32 8225.32)" fill="none" stroke="#222025" stroke-width="1"><rect width="36" height="36" rx="18" stroke="none"/><rect x="0.5" y="0.5" width="35" height="35" rx="17.5" fill="none"/></g><rect id="innerDot" data-name="Rectangle 347" width="14" height="14" rx="7" transform="translate(614.32 8236.32)" fill="#222025"/></g></svg>');
  var owl = $(".home-3-slider");
  owl.owlCarousel();
  $(".home-slider-3 .arrows .next").click(function () {
    owl.trigger("next.owl.carousel");
  });
  $(".home-slider-3 .arrows .prev").click(function () {
    owl.trigger("prev.owl.carousel");
  });
});
$(function () {
  $(".single-slider").owlCarousel({
    autoplay: true,
    autoplayTimeout: 5000,
    loop: true,
    margin: 0,
    dots: true,
    nav: false,
    responsive: {
        0:{
            items:1
        },
        600:{
            items:1
        },
        1000:{
            items:1
        }
    }
  });
  $(".single-slider .owl-dots").addClass("owl-dots-1");
  $(".single-slider .owl-dot").html('<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 36 36"><g id="Group_483" data-name="Group 483" transform="translate(-603.32 -8225.32)"><g id="Stroke" data-name="Rectangle 348" transform="translate(603.32 8225.32)" fill="none" stroke="#222025" stroke-width="1"><rect width="36" height="36" rx="18" stroke="none"/><rect x="0.5" y="0.5" width="35" height="35" rx="17.5" fill="none"/></g><rect id="innerDot" data-name="Rectangle 347" width="14" height="14" rx="7" transform="translate(614.32 8236.32)" fill="#222025"/></g></svg>');
});



$(function () {
  $(".portfolio-details-slider").owlCarousel({
    autoplay: true,
    autoplayTimeout: 5000,
    loop: true,
    margin: 100,
    dots: true,
    nav: false,
    responsive: {
        0:{
            items:1
        },
        600:{
            items:1
        },
        1000:{
            items:1
        }
    }
  });
  $(".portfolio-details-slider .owl-dots").addClass("owl-dots-1");
  $(".portfolio-details-slider .owl-dot").html('<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 36 36"><g id="Group_483" data-name="Group 483" transform="translate(-603.32 -8225.32)"><g id="Stroke" data-name="Rectangle 348" transform="translate(603.32 8225.32)" fill="none" stroke="#222025" stroke-width="1"><rect width="36" height="36" rx="18" stroke="none"/><rect x="0.5" y="0.5" width="35" height="35" rx="17.5" fill="none"/></g><rect id="innerDot" data-name="Rectangle 347" width="14" height="14" rx="7" transform="translate(614.32 8236.32)" fill="#222025"/></g></svg>');
});






$('.portfolio-1 .portfolio-lists').masonry();




$('.portfolio-tab .portfolio-lists').isotope({
  itemSelector: '.item',
  layoutMode: 'fitRows'
});

$(".portfolio-tab .tabs-menu ul li").click(function () {
  $(".portfolio-tab .tabs-menu ul li").removeClass("active");
  $(this).addClass("active");
  var selector;
  selector = $(this).attr("data-filter");
  $(".portfolio-tab .portfolio-lists").isotope({
    filter: selector,
  });
  return false;
});



$('.portfolio-tab-masonry .portfolio-lists').isotope({
  itemSelector: '.item',
  // layoutMode: 'fitRows'

});

$(".portfolio-tab-masonry .tabs-menu ul li").click(function () {
  $(".portfolio-tab-masonry .tabs-menu ul li").removeClass("active");
  $(this).addClass("active");
  var selector;
  selector = $(this).attr("data-filter");
  $(".portfolio-tab-masonry .portfolio-lists").isotope({
    filter: selector,
  });
  return false;
});


$('.masonry-style-3 .portfolio-lists').isotope({layoutMode: 'packery'});






$('.portfolio-lists .icon').magnificPopup({
  // delegate: 'a',
  type: 'image',
  tLoading: 'Loading image #%curr%...',
  mainClass: 'mfp-img-mobile',
  gallery: {
    enabled: true,
    navigateByImgClick: true,
    preload: [0,1]
  }
});





  
  

});
