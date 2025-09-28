(function($){
  function initEditor(textarea, settings){
    if (!window.wp || !wp.codeEditor || !wp.codeEditor.initialize) return;
    if (!textarea) return;
    try {
      var ed = wp.codeEditor.initialize(textarea, settings || {});
      // Keep a reference to the CodeMirror instance for later syncing
      try {
        var cm = ed && ed.codemirror ? ed.codemirror : null;
        if (cm) {
          $(textarea).data('uplgCM', cm);
          // Continuously mirror CodeMirror content back to the underlying textarea
          try {
            cm.on('change', function(inst){
              try {
                textarea.value = inst.getValue();
              } catch(e){}
            });
            // Ensure initial sync as well
            try { textarea.value = cm.getValue(); } catch(e){}
          } catch(e){}
        }
      } catch(e){}
      return ed;
    } catch(e) {
      // fail silently if codemirror not available
      return null;
    }
  }

  function nextIndexForFlags(){
    var $rows = $('#uplg-schema-rows .uplg-schema-row').not('.uplg-schema-template');
    return $rows.length;
  }

  $(function(){
    var settings = (typeof uplgCodeMirrorSettings !== 'undefined') ? uplgCodeMirrorSettings : {php:{},js:{},scss:{},css:{}};
    var editors = [];

    // Initialize legacy fixed fields
    var mapping = {
      '_uplg_php_code': settings.php || {},
      '_uplg_js_code': settings.js || {},
      '_uplg_scss_code': settings.scss || {},
      '_uplg_css_code': settings.css || {}
    };
    Object.keys(mapping).forEach(function(name){
      var $ta = $('textarea[name="'+name+'"]');
      if ($ta.length){
        var ed = initEditor($ta.get(0), mapping[name]);
        if (ed && ed.codemirror) { editors.push(ed); }
      }
    });

    // Initialize any dynamic code textareas marked with data-codemirror
    $('textarea[data-codemirror]').each(function(){
      var mode = $(this).attr('data-codemirror');
      var cfg = {};
      if (mode === 'php') cfg = settings.php || {};
      else if (mode === 'scss') cfg = settings.scss || {};
      else if (mode === 'js' || mode === 'javascript') cfg = settings.js || {};
      else if (mode === 'css') cfg = settings.css || {};
      var ed = initEditor(this, cfg);
      if (ed && ed.codemirror) { editors.push(ed); }
    });

    // Schema repeater UI
    $(document).on('click', '#uplg-schema-add', function(e){
      e.preventDefault();
      var $tpl = $('.uplg-schema-template').first().clone(true);
      $tpl.removeClass('uplg-schema-template').show();
      // Replace __i__ placeholder in checkbox name with next index
      var idx = nextIndexForFlags();
      $tpl.find('input[type="checkbox"]').each(function(){
        var n = $(this).attr('name');
        $(this).attr('name', n.replace('__i__', String(idx)));
      });
      $('#uplg-schema-rows').append($tpl);
    });

    $(document).on('click', '.uplg-schema-remove', function(e){
      e.preventDefault();
      var $row = $(this).closest('.uplg-schema-row');
      if ($row.hasClass('uplg-schema-template')) return;
      $row.remove();
    });

    // Ensure CodeMirror content is synced back to the textarea on submit
    var $form = $('#post');
    if ($form.length){
      $form.on('submit', function(){
        try {
          // Sync known editors
          editors.forEach(function(ed){
            if (ed && ed.codemirror && ed.codemirror.getTextArea) {
              var ta = ed.codemirror.getTextArea();
              if (ta) { ta.value = ed.codemirror.getValue(); }
            }
          });
          // Also sync any textarea with a stored CodeMirror instance
          $('textarea[data-codemirror], textarea[name^="_uplg_"]').each(function(){
            var cm = $(this).data('uplgCM');
            if (cm && cm.getValue) {
              this.value = cm.getValue();
            }
          });
        } catch(e) {}
      });
    }
  });
})(jQuery);
