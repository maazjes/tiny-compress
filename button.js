jQuery(document).ready(function($) {
    $('.tc-compress, .tc-restore').click(function(e) {
        e.preventDefault();
        var button = $(this);
        var imageId = button.data('id');
        var messageContainer = button.closest('.tiny-compress').find('.tc-message');
        var buttonClass = button.hasClass('tc-compress') ? 'tc-compress' : 'tc-restore';
        var buttonText = button.textContent;
        button.html("<span style='visibility: hidden;'>Compress</span><div style='border-left-color: " + (buttonClass !== 'tc-compress' ? "#2271b1;" : "white") + "' class='tc-spinner'></div>");

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: buttonClass.replace("-", "_"),
                image_id: imageId,
                nonce: tinyCompress.nonce
            },
            success: function(response) {
                var message = '';

                if (response.success) {
                    message += '<span style="color: green;">' + String(response.success) + '</span><br>';
                    button.removeClass(buttonClass);
                    if (buttonClass === 'tc-compress') {
                        button.addClass('tc-restore');
                        button.removeClass('button-primary');
                        button.html('Restore');
                    } else {
                        button.addClass('tc-compress');
                        button.addClass('button-primary');
                        button.html('Compress');
                    }
                }

                if (response.errors.length > 0) {
                    var errorMessage = response.errors.join('<br>');
                    message += '<span style="color: red;">' + String(errorMessage) + '</span>';
                    button.html(buttonText);
                }

                if (message === '') {
                    message = 'Unknown response format';
                    button.html(buttonText);
                }

                messageContainer.html(message);
            },
            error: function(_xhr, textStatus, errorThrown) {
                var errorMessage = 'AJAX request failed: ' + textStatus + ' (' + errorThrown + ')';
                var errorElement = $('<div/>', { text: errorMessage });
                messageContainer.html(errorElement);
                spinner.replaceWith(button);
            }
        });
    });
});