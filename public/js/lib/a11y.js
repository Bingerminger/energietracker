// =====================================================================
// A11y-Hilfen (N1009)
// =====================================================================

// Verknüpft in einem Container jedes `.field`-Paar (Label + erstes Control)
// per for/id, damit Screenreader den Feldnamen ansagen und ein Klick aufs
// Label das Feld fokussiert. Idempotent — Controls mit vorhandener id und
// Labels mit vorhandenem `for` werden übersprungen, sodass die Funktion
// gefahrlos nach jedem dynamisch ergänzten Eintrag erneut laufen kann.
let __fieldSeq = 0;
export function associateFieldLabels(root) {
  if (!root) return;
  root.querySelectorAll('.field').forEach(field => {
    const label = field.querySelector(':scope > label');
    const control = field.querySelector(':scope > input, :scope > select, :scope > textarea');
    if (!label || !control || label.hasAttribute('for') || control.id) return;
    const id = 'a11y-field-' + (++__fieldSeq);
    control.id = id;
    label.setAttribute('for', id);
  });
}
