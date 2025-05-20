jQuery(function($) {
  $('#brevo-refresh-attributes').on('click', function(e) {
    e.preventDefault();
    $('#brevo-attribute-results').text('Fetching…').css('color','#0073aa');
    $.post(ksjBrevoData.ajax_url, {
      action: 'brevo_fetch_attributes',
      security: ksjBrevoData.nonce
    }, function(response) {
      if (response.success) {
        $('#brevo-attribute-results').text(response.data.message).css('color','green');
        $('#brevo-attribute-list').html(response.data.attributes_html);
      } else {
        $('#brevo-attribute-results').text(response.data.message).css('color','red');
      }
    }).fail(function(xhr) {
      $('#brevo-attribute-results').text('Request failed.').css('color','red');
    });
  });
});
