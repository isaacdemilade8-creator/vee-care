import { PackageCheck, ShoppingBag } from 'lucide-react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { FormEvent } from 'react';
import toast from 'react-hot-toast';
import { Link } from 'react-router-dom';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { SkeletonRows } from '../components/Skeleton';
import { usePharmacyMedicines, usePharmacyOrders } from '../hooks/useEnterprise';
import { endpoints } from '../services/endpoints';
import styles from './TablePage.module.scss';

function formValues(form: HTMLFormElement) {
  return Object.fromEntries(new FormData(form)) as Record<string, string>;
}

export function MedicineOrderPage() {
  const queryClient = useQueryClient();
  const medicines = usePharmacyMedicines();
  const orders = usePharmacyOrders();
  const createOrder = useMutation({
    mutationFn: (payload: unknown) => endpoints.createMedicineOrder(payload),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['pharmacy-orders'] }),
        queryClient.invalidateQueries({ queryKey: ['pharmacy-medicines'] }),
      ]);
      toast.success('Medicine order sent to pharmacy');
    },
  });

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const values = formValues(event.currentTarget);

    createOrder.mutate({
      medicine_id: Number(values.medicine_id),
      quantity: Number(values.quantity || 1),
      notes: values.notes || null,
    }, {
      onSuccess: () => event.currentTarget.reset(),
    });
  };

  if (medicines.isLoading || orders.isLoading) {
    return <SkeletonRows rows={5} />;
  }

  const medicineRows = medicines.data?.medicines?.data ?? [];
  const orderRows = orders.data?.orders?.data ?? [];

  return (
    <div className={styles.page}>
      <div className={styles.header}>
        <div>
          <span>Pharmacy pickup</span>
          <h2>Order Medicine</h2>
        </div>
        <div>
          <Link to="/dashboard"><Button variant="secondary">Back to dashboard</Button></Link>
        </div>
      </div>

      <Card>
        <div className={styles.sectionTitle}>
          <span>Pickup request</span>
          <h3>Send an order to the pharmacy</h3>
          <p>Choose an available medicine and quantity. The pharmacy will prepare it and notify you when it is ready for pickup.</p>
        </div>
        <form className={styles.form} onSubmit={submit}>
          <div className={styles.formRow}>
            <select name="medicine_id" required>
              <option value="">Select medicine</option>
              {medicineRows.map((medicine) => (
                <option key={medicine.id} value={medicine.id}>
                  {medicine.name} - {medicine.stock} in stock
                </option>
              ))}
            </select>
            <input name="quantity" type="number" min="1" defaultValue="1" placeholder="Quantity" required />
            <input name="notes" placeholder="Pickup note, allergies, or preferred brand" />
          </div>
          <Button disabled={createOrder.isPending || !medicineRows.length}>
            <ShoppingBag size={17} />
            Send pickup order
          </Button>
        </form>
      </Card>

      <Card>
        <div className={styles.sectionTitle}>
          <span>Your requests</span>
          <h3>Pickup order history</h3>
        </div>
        <div className={styles.table}>
          {orderRows.map((order) => (
            <article key={order.id}>
              <strong>{order.medicine?.name ?? 'Medicine order'}</strong>
              <span>Qty: {order.quantity}</span>
              <span className={styles.badge}>{order.status}</span>
              <span>Pickup code: {order.pickup_code}</span>
            </article>
          ))}
          {!orderRows.length ? (
            <div className={styles.emptyState}>
              <PackageCheck size={32} />
              <h3>You have no medicine orders yet</h3>
              <p>Your pharmacy pickup requests will appear here after you place an order.</p>
            </div>
          ) : null}
        </div>
      </Card>
    </div>
  );
}
