import { render, screen } from '@testing-library/react';
import App from './App';

describe('App scaffold', () => {
  it('renders portal heading', async () => {
    render(<App />);
    expect(await screen.findByRole('heading', { name: /Regulatory Operations & Analytics Portal/i })).toBeInTheDocument();
    expect(await screen.findByRole('heading', { name: /Session & Authentication/i })).toBeInTheDocument();
  });
});
