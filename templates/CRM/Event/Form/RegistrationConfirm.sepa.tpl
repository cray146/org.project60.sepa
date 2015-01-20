{*-------------------------------------------------------+
| Project 60 - SEPA direct debit                         |
| Copyright (C) 2013-2015 SYSTOPIA                       |
| Author: B. Endres (endres -at- systopia.de)            |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+-------------------------------------------------------*}

{if $bank_iban}
<div id="sepa-new-info-block" class="crm-group">
  <div class="header-dark">{ts}Direct Debit Payment{/ts}</div>
  <p id="sepa-confirm-text-account">{ts}This payment will be debited from the following account:{/ts}</p>
  <table class="sepa-confirm-text-account-details display" id="sepa-confirm-text-account-details">
    <tr><td>{ts}IBAN{/ts}</td> <td>{$bank_iban}</td> </tr>
    <tr><td>{ts}BIC{/ts}</td>  <td>{$bank_bic}</td>  </tr>
  </table>
</div>

<script type="text/javascript">
cj("div.credit_card-group").replaceWith(cj("#sepa-new-info-block"));
cj("div.billing_name_address-group").hide();
</script>
{/if}