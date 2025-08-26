jQuery(function($){
  const btn  = $('#alpha_logo_btn');
  const clr  = $('#alpha_logo_clear');
  const input= $('#publisher_logo_id');
  const prev = $('#alpha_logo_preview');
  let frame;

  btn.on('click', function(e){
    e.preventDefault();
    if (frame) { frame.open(); return; }
    frame = wp.media({ title: 'Selecionar logo', button:{text:'Usar esta imagem'}, multiple:false });
    frame.on('select', function(){
      const att = frame.state().get('selection').first().toJSON();
      input.val(att.id);
      prev.attr('src', att.url).show();
    });
    frame.open();
  });
  clr.on('click', function(){
    input.val('');
    prev.hide().attr('src','');
  });
});
