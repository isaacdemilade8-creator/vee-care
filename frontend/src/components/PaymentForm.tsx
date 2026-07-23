import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { Button } from './Button';
import { TextField } from './FormField';

const paymentSchema = z.object({
  cardNumber: z.string().regex(/^\d{16}$/, 'Enter a valid 16-digit card number'),
  cardName: z.string().min(3, 'Enter the cardholder name'),
  expiry: z.string().regex(/^\d{2}\/\d{2}$/, 'Use MM/YY format'),
  cvv: z.string().regex(/^\d{3,4}$/, 'Enter a valid CVV'),
});

type PaymentFormData = z.output<typeof paymentSchema>;

interface PaymentFormProps {
  onSubmit: (data: PaymentFormData) => void;
  isPending?: boolean;
  submitLabel?: string;
}

export function PaymentForm({ onSubmit, isPending, submitLabel = 'Pay' }: PaymentFormProps) {
  const { register, handleSubmit, formState: { errors } } = useForm<z.input<typeof paymentSchema>, unknown, PaymentFormData>({
    resolver: zodResolver(paymentSchema),
  });

  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      <div className="formRow">
        <TextField label="Card number" placeholder="1234 5678 9012 3456" error={errors.cardNumber?.message} {...register('cardNumber')} />
      </div>
      <div className="formRow">
        <TextField label="Cardholder name" placeholder="John Doe" error={errors.cardName?.message} {...register('cardName')} />
      </div>
      <div className="formRow">
        <TextField label="Expiry (MM/YY)" placeholder="12/26" error={errors.expiry?.message} {...register('expiry')} />
        <TextField label="CVV" placeholder="123" error={errors.cvv?.message} {...register('cvv')} />
      </div>
      <p style={{ color: 'var(--app-muted)', fontSize: '0.8rem', margin: '0.5rem 0' }}>
        This is a demo &mdash; no real payment will be processed.
      </p>
      <Button disabled={isPending}>{isPending ? 'Processing...' : submitLabel}</Button>
    </form>
  );
}
