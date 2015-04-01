<div class="narrow">

  <div style="float: right;"><img src="/images/hedgy-large.png" width="300"></div>

  <div style="font-size: 1.6em; margin-bottom: 10px; font-weight: bold;">
    Hello <?= ($this->user->name ?: friendly_url($this->user->url)) ?>!
  </div>
  <div style="font-size: 1.4em; margin-bottom: 10px;">
    On what URL can I find your food posts?
  </div>
  <div>
    I'll be looking for food and drink posts marked up with <a href="http://indiewebcamp.com/h-entry">Microformats 2</a>.
  </div>

  <div style="margin-top: 30px;">
    <form action="http://setup.hedgy.dev/login" method="get" class="form-inline">
      <input type="text" id="feed-url" class="form-control" style="width: 220px;" placeholder="your food + drink URL" value=<?= $this->user->feed_url ?>>
      <input type="button" value="Save" class="btn btn-default" id="save-url">
    </form>
  </div>

  <div style="margin-top: 30px; display: none;" id="success">
    <div style="font-size: 1.4em;">
      Got it! I'll check your website for new food and drink posts periodically!
    </div>
  </div>

</div>
<script>
$(function(){
  $("#save-url").click(function(){
    $.post('/configure/save', {
      url: $("#feed-url").val()
    }, function(data){
      $("#success").show();
    });
  });
});
</script>