(function($){
  function initEditor(textarea, settings){
    if (!window.wp || !wp.codeEditor || !wp.codeEditor.initialize) return;
    if (!textarea) return;
    var ed = wp.codeEditor.initialize(textarea, settings || {});
    return ed;
  }

  $(function(){
    if (typeof uplgCodeMirrorSettings === 'undefined') return;
    // Map field name => settings key
    var mapping = {
      '_uplg_php_code': uplgCodeMirrorSettings.php || {},
      '_uplg_js_code': uplgCodeMirrorSettings.js || {},
      '_uplg_scss_code': uplgCodeMirrorSettings.scss || {},
      '_uplg_css_code': uplgCodeMirrorSettings.css || {}
    };

    Object.keys(mapping).forEach(function(name){
      var $ta = $('textarea[name="'+name+'"]');
      if ($ta.length){ initEditor($ta.get(0), mapping[name]); }
    });
  });
})(jQuery);
