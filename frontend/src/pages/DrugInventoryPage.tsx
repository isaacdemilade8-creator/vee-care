import { AlertTriangle, PackageCheck, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { FormEvent } from 'react';
import { useState } from 'react';
import toast from 'react-hot-toast';
import { Link } from 'react-router-dom';
import { Button } from '../components/Button';
import { Card, StatCard } from '../components/Card';
import { SkeletonRows } from '../components/Skeleton';
import { useEnterprisePharmacy } from '../hooks/useEnterprise';
import { endpoints } from '../services/endpoints';
import type { Medicine } from '../types';
import styles from './TablePage.module.scss';

function formValues(form: HTMLFormElement) {
  return Object.fromEntries(new FormData(form)) as Record<string, string>;
}

function medicineValue(medicine: Medicine, snake: keyof Medicine, camel: keyof Medicine) {
  const value = medicine[camel] ?? medicine[snake] ?? '';

  return typeof value === 'string' || typeof value === 'number' ? String(value) : '';
}

export function DrugInventoryPage() {
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [stockState, setStockState] = useState('');
  const [selected, setSelected] = useState<Medicine | null>(null);
  const filters = {
    ...(search ? { search } : {}),
    ...(stockState ? { stock_state: stockState } : {}),
  };
  const inventory = useEnterprisePharmacy(true, filters);
  const invalidateInventory = () => Promise.all([
    queryClient.invalidateQueries({ queryKey: ['enterprise-pharmacy'] }),
    queryClient.invalidateQueries({ queryKey: ['pharmacy-medicines'] }),
    queryClient.invalidateQueries({ queryKey: ['enterprise-dashboard'] }),
  ]);
  const updateMedicine = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: unknown }) => endpoints.updateMedicine(id, payload),
    onSuccess: async () => {
      await invalidateInventory();
      toast.success('Medicine updated');
      setSelected(null);
    },
  });
  const deleteMedicine = useMutation({
    mutationFn: (id: number) => endpoints.deleteMedicine(id),
    onSuccess: async () => {
      await invalidateInventory();
      toast.success('Medicine removed');
      setSelected(null);
    },
  });
  const adjustStock = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: unknown }) => endpoints.adjustMedicineStock(id, payload),
    onSuccess: async () => {
      await invalidateInventory();
      toast.success('Stock movement recorded');
    },
  });

  const medicines = inventory.data?.medicines?.data ?? [];
  const summary = inventory.data?.summary;

  const submitUpdate = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!selected) return;
    const values = formValues(event.currentTarget);

    updateMedicine.mutate({
      id: selected.id,
      payload: {
        name: values.name,
        sku: values.sku || null,
        category: values.category || null,
        dosage_form: values.dosage_form || null,
        strength: values.strength || null,
        manufacturer: values.manufacturer || null,
        batch_number: values.batch_number || null,
        storage_location: values.storage_location || null,
        reorder_level: Number(values.reorder_level || 0),
        unit_price: Number(values.unit_price || 0),
        status: values.status,
        expires_at: values.expires_at || null,
      },
    });
  };

  const submitMovement = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!selected) return;
    const values = formValues(event.currentTarget);
    const quantity = Number(values.quantity || 0);
    const movementType = values.type || 'restock';
    const delta = ['dispense', 'waste'].includes(movementType) ? -quantity : quantity;

    adjustStock.mutate({
      id: selected.id,
      payload: {
        delta,
        type: movementType,
        reason: values.reason || 'Inventory adjustment',
        reference: values.reference || null,
      },
    }, {
      onSuccess: () => event.currentTarget.reset(),
    });
  };

  return (
    <div className={styles.page}>
      <div className={styles.header}>
        <div>
          <span>Drug inventory</span>
          <h2>Inventory System</h2>
        </div>
        <div>
          <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search drugs, SKU, batch" />
          <select value={stockState} onChange={(event) => setStockState(event.target.value)} aria-label="Inventory filter">
            <option value="">All stock</option>
            <option value="low">Low stock</option>
            <option value="expired">Expired</option>
          </select>
          <Link to="/pharmacy/medicines/new"><Button><Plus size={17} /> Add drug</Button></Link>
        </div>
      </div>

      <div className={styles.stats}>
        <StatCard label="Inventory items" value={summary?.items ?? 0} />
        <StatCard label="Active drugs" value={summary?.active ?? 0} />
        <StatCard label="Low stock" value={summary?.lowStock ?? 0} />
        <StatCard label="Expired" value={summary?.expired ?? 0} />
      </div>

      <div className={styles.adminGrid}>
        <Card>
          <div className={styles.sectionTitle}>
            <span>Inventory register</span>
            <h3>Drugs and stock levels</h3>
            <p>Search, select, edit, and track stock status from one place.</p>
          </div>
          {inventory.isLoading ? <SkeletonRows /> : (
            <div className={styles.table}>
              {medicines.map((medicine: Medicine) => {
                const expired = Boolean((medicine.expiresAt ?? medicine.expires_at) && new Date(medicine.expiresAt ?? medicine.expires_at ?? '') < new Date());
                const low = medicine.stock <= Number(medicine.reorderLevel ?? medicine.reorder_level ?? 0);

                return (
                  <button
                    className={`${styles.tableAction} ${selected?.id === medicine.id ? styles.selectedRow : ''}`}
                    key={medicine.id}
                    type="button"
                    onClick={() => setSelected(medicine)}
                  >
                    <strong>{medicine.name}</strong>
                    <span>{medicine.category ?? 'Uncategorized'} | {medicineValue(medicine, 'dosage_form', 'dosageForm') || 'Form not set'}</span>
                    <span>Stock: {medicine.stock} | Reorder: {medicine.reorderLevel ?? medicine.reorder_level ?? 0}</span>
                    <span>{medicine.sku ?? 'No SKU'} | Batch: {medicineValue(medicine, 'batch_number', 'batchNumber') || 'N/A'}</span>
                    {low || expired ? (
                      <span className={styles.badge}><AlertTriangle size={14} /> {expired ? 'Expired' : 'Low stock'}</span>
                    ) : <span className={styles.badge}>{medicine.status ?? 'active'}</span>}
                  </button>
                );
              })}
              {!medicines.length ? (
                <div className={styles.emptyState}>
                  <Search size={32} />
                  <h3>No drugs found</h3>
                  <p>Add a drug or adjust your filters to see inventory records.</p>
                </div>
              ) : null}
            </div>
          )}
        </Card>

        <div className={styles.page}>
          <Card>
            <div className={styles.sectionTitle}>
              <span>Selected drug</span>
              <h3>{selected?.name ?? 'Choose a drug'}</h3>
              <p>{selected ? 'Update details, record stock movement, and review recent activity.' : 'Select a drug from the register to manage it.'}</p>
            </div>

            {selected ? (
              <form className={styles.form} onSubmit={submitUpdate}>
                <div className={styles.formRow}>
                  <input name="name" defaultValue={selected.name} placeholder="Name" required />
                  <input name="sku" defaultValue={selected.sku ?? ''} placeholder="SKU" />
                  <input name="category" defaultValue={selected.category ?? ''} placeholder="Category" />
                </div>
                <div className={styles.formRow}>
                  <input name="dosage_form" defaultValue={String(medicineValue(selected, 'dosage_form', 'dosageForm'))} placeholder="Dosage form" />
                  <input name="strength" defaultValue={selected.strength ?? ''} placeholder="Strength" />
                  <input name="manufacturer" defaultValue={selected.manufacturer ?? ''} placeholder="Manufacturer" />
                </div>
                <div className={styles.formRow}>
                  <input name="batch_number" defaultValue={String(medicineValue(selected, 'batch_number', 'batchNumber'))} placeholder="Batch number" />
                  <input name="storage_location" defaultValue={String(medicineValue(selected, 'storage_location', 'storageLocation'))} placeholder="Storage location" />
                  <select name="status" defaultValue={selected.status ?? 'active'}>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </div>
                <div className={styles.formRow}>
                  <input name="reorder_level" type="number" min="0" defaultValue={selected.reorderLevel ?? selected.reorder_level ?? 0} placeholder="Reorder level" />
                  <input name="unit_price" type="number" min="0" defaultValue={selected.unitPrice ?? selected.unit_price ?? 0} placeholder="Unit price" />
                  <input name="expires_at" type="date" defaultValue={String(selected.expiresAt ?? selected.expires_at ?? '').slice(0, 10)} aria-label="Expiry date" />
                </div>
                <div className={styles.formActions}>
                  <Button disabled={updateMedicine.isPending}><Pencil size={17} /> Save changes</Button>
                  <Button type="button" variant="secondary" disabled={deleteMedicine.isPending} onClick={() => deleteMedicine.mutate(selected.id)}>
                    <Trash2 size={17} />
                    Delete
                  </Button>
                </div>
              </form>
            ) : null}
          </Card>

          {selected ? (
            <Card>
              <div className={styles.sectionTitle}>
                <span>Stock control</span>
                <h3>Record movement</h3>
              </div>
              <form className={styles.form} onSubmit={submitMovement}>
                <div className={styles.formRow}>
                  <select name="type" defaultValue="restock">
                    <option value="restock">Restock</option>
                    <option value="dispense">Dispense</option>
                    <option value="waste">Waste/expired removal</option>
                    <option value="return">Return</option>
                    <option value="correction">Correction</option>
                  </select>
                  <input name="quantity" type="number" min="1" placeholder="Quantity" required />
                  <input name="reference" placeholder="Reference, invoice, or order" />
                </div>
                <input name="reason" placeholder="Reason" required />
                <Button disabled={adjustStock.isPending}><PackageCheck size={17} /> Record movement</Button>
              </form>
              <div className={styles.table}>
                {(selected.stockMovements ?? selected.stock_movements ?? []).map((movement) => (
                  <article key={movement.id}>
                    <strong>{movement.type}</strong>
                    <span>{movement.delta > 0 ? '+' : ''}{movement.delta}</span>
                    <span>{movement.reason}</span>
                  </article>
                ))}
              </div>
            </Card>
          ) : null}
        </div>
      </div>
    </div>
  );
}
