import { ArrowLeft, BadgeDollarSign, Boxes, PackageCheck, Pill, ShieldCheck } from 'lucide-react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { FormEvent } from 'react';
import toast from 'react-hot-toast';
import { Link, useNavigate } from 'react-router-dom';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { endpoints } from '../services/endpoints';
import styles from './AddMedicinePage.module.scss';

type CreateMedicinePayload = {
  name: string;
  sku: string | null;
  stock: number;
  reorder_level: number;
  unit_price: number;
  category: string | null;
  dosage_form: string | null;
  strength: string | null;
  manufacturer: string | null;
  batch_number: string | null;
  storage_location: string | null;
  status: 'active';
  expires_at: string | null;
};

function formValues(form: HTMLFormElement) {
  return Object.fromEntries(new FormData(form)) as Record<string, string>;
}

export function AddMedicinePage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const createMedicine = useMutation({
    mutationFn: (payload: CreateMedicinePayload) => endpoints.createMedicine(payload),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['enterprise-pharmacy'] }),
        queryClient.invalidateQueries({ queryKey: ['enterprise-dashboard'] }),
      ]);
      toast.success('Medicine added');
      navigate('/pharmacy/inventory');
    },
  });

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const values = formValues(event.currentTarget);

    createMedicine.mutate({
      name: values.name,
      sku: values.sku || null,
      stock: Number(values.stock || 0),
      reorder_level: Number(values.reorder_level || 10),
      unit_price: Number(values.unit_price || 0),
      category: values.category || null,
      dosage_form: values.dosage_form || null,
      strength: values.strength || null,
      manufacturer: values.manufacturer || null,
      batch_number: values.batch_number || null,
      storage_location: values.storage_location || null,
      status: 'active',
      expires_at: values.expires_at || null,
    });
  };

  return (
    <div className={styles.page}>
      <div className={styles.header}>
        <div>
          <span>Pharmacy inventory</span>
          <h2>Add Medicine</h2>
          <p>Register drug details, batch data, pricing, and reorder rules for the inventory system.</p>
        </div>
        <div>
          <Link to="/pharmacy/inventory"><Button variant="secondary"><ArrowLeft size={17} /> Inventory</Button></Link>
        </div>
      </div>

      <div className={styles.layout}>
        <Card className={styles.formCard}>
          <form className={styles.form} onSubmit={submit}>
            <fieldset>
              <legend><Pill size={18} /> Drug identity</legend>
              <div className={styles.formRow}>
                <label>
                  <span>Medicine name</span>
                  <input name="name" placeholder="Amoxicillin" required />
                </label>
                <label>
                  <span>Strength</span>
                  <input name="strength" placeholder="500mg" />
                </label>
              </div>
              <div className={styles.formRow}>
                <label>
                  <span>Dosage form</span>
                  <select name="dosage_form" defaultValue="">
                    <option value="">Select form</option>
                    <option value="Tablet">Tablet</option>
                    <option value="Capsule">Capsule</option>
                    <option value="Syrup">Syrup</option>
                    <option value="Injection">Injection</option>
                    <option value="Cream">Cream</option>
                    <option value="Drops">Drops</option>
                    <option value="Inhaler">Inhaler</option>
                  </select>
                </label>
                <label>
                  <span>Category</span>
                  <input name="category" placeholder="Antibiotic" />
                </label>
              </div>
            </fieldset>

            <fieldset>
              <legend><Boxes size={18} /> Batch and storage</legend>
              <div className={styles.formRow}>
                <label>
                  <span>SKU</span>
                  <input name="sku" placeholder="AMX-500" />
                </label>
                <label>
                  <span>Batch number</span>
                  <input name="batch_number" placeholder="BCH-2026-01" />
                </label>
              </div>
              <div className={styles.formRow}>
                <label>
                  <span>Manufacturer</span>
                  <input name="manufacturer" placeholder="Manufacturer" />
                </label>
                <label>
                  <span>Storage location</span>
                  <input name="storage_location" placeholder="Shelf A2" />
                </label>
              </div>
            </fieldset>

            <fieldset>
              <legend><BadgeDollarSign size={18} /> Stock and pricing</legend>
              <div className={styles.formRow}>
                <label>
                  <span>Initial stock</span>
                  <input name="stock" type="number" min="0" placeholder="0" />
                </label>
                <label>
                  <span>Reorder level</span>
                  <input name="reorder_level" type="number" min="0" defaultValue="10" placeholder="10" />
                </label>
              </div>
              <div className={styles.formRow}>
                <label>
                  <span>Unit price</span>
                  <input name="unit_price" type="number" min="0" placeholder="0" />
                </label>
                <label>
                  <span>Expiry date</span>
                  <input name="expires_at" type="date" />
                </label>
              </div>
            </fieldset>

            <div className={styles.actions}>
              <Button disabled={createMedicine.isPending}>
                <PackageCheck size={17} />
                {createMedicine.isPending ? 'Adding medicine...' : 'Add medicine'}
              </Button>
              <Link to="/pharmacy/inventory"><Button type="button" variant="secondary">Cancel</Button></Link>
            </div>
          </form>
        </Card>

        <aside className={styles.sidePanel}>
          <Card>
            <ShieldCheck size={24} />
            <h3>Inventory-safe entry</h3>
            <p>Set reorder level and expiry date now so low-stock and expired-drug filters work immediately.</p>
          </Card>
          <Card>
            <Boxes size={24} />
            <h3>Batch tracking</h3>
            <p>Batch number and storage location help pharmacy staff locate stock quickly during pickup preparation.</p>
          </Card>
        </aside>
      </div>
    </div>
  );
}
